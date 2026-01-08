<?php
/**
 * AJAX endpoint to fetch payment profiles from PaymentHood API
 *
 * GET  -> returns { success: true, profiles: [...] }
 *      -> GET with ?proxy=1&u=... proxies icon image
 * POST -> supports:
 *   - { logError: true, ... } (client-side error logging)
 *   - { profileId: 123 } (store selection in session)
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Handle icon proxy mode (GET with ?proxy=1&u=...)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['proxy'])) {
    $url = isset($_GET['u']) ? (string) $_GET['u'] : '';
    $url = trim($url);
    
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }
    
    if (strlen($url) > 2048 || $url === '') {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid url';
        exit;
    }
    
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid url';
        exit;
    }
    
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    
    if ($scheme !== 'https') {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Only https allowed';
        exit;
    }
    
    // Allowlist blob storage hosts
    $allowed = (
        $host === 'phpaymentstorageaccount.blob.core.windows.net'
        || substr($host, -24) === '.blob.core.windows.net'
        || $host === 'paymenthood.com'
        || substr($host, -15) === '.paymenthood.com'
        || substr($host, -13) === '.azureedge.net'
    );
    
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Host not allowed';
        exit;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: image/*',
        'User-Agent: WHMCS-PaymentHood-IconProxy/1.0',
    ]);
    
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Upstream failed';
        exit;
    }
    
    // Fix content type if wrong
    if ($contentType === '' || $contentType === 'application/octet-stream') {
        $ext = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            $contentType = 'image/svg+xml';
        } elseif ($ext === 'png') {
            $contentType = 'image/png';
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $contentType = 'image/jpeg';
        } else {
            $contentType = 'image/*';
        }
    }
    
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=86400');
    echo $body;
    exit;
}

// Ensure we can still emit JSON even if something echoes before headers.
if (!ob_get_level()) {
    ob_start();
}

/**
 * Emit a JSON response and end.
 */
$paymenthoodRespond = function (int $statusCode, array $payload): void {
    // Many servers replace/strip 4xx/5xx bodies. Always return 200 and carry the real status in JSON.
    $payload['httpStatus'] = $statusCode;

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(200);
    }
    // Clear any buffered HTML/output before returning JSON.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
};

set_exception_handler(function (\Throwable $e) use ($paymenthoodRespond): void {
    // Can't rely on WHMCS logger here; init.php might not have loaded.
    error_log('PaymentHood get-payment-profiles exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $paymenthoodRespond(500, [
        'success' => false,
        'error' => 'Internal error',
        'detail' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) use ($paymenthoodRespond): bool {
    // Convert warnings/notices into exceptions so we can surface them.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $paymenthoodRespond(500, [
        'success' => false,
        'error' => 'PHP error',
        'detail' => $message,
        'file' => basename($file),
        'line' => $line,
    ]);
    return true;
});

register_shutdown_function(function () use ($paymenthoodRespond): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    error_log('PaymentHood get-payment-profiles fatal: ' . ($err['message'] ?? '') . ' in ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));

    $paymenthoodRespond(500, [
        'success' => false,
        'error' => 'Fatal error',
        'detail' => $err['message'] ?? 'Unknown fatal error',
        'file' => isset($err['file']) ? basename((string) $err['file']) : null,
        'line' => $err['line'] ?? null,
    ]);
});

try {
    require_once __DIR__ . '/../../../init.php';
    require_once __DIR__ . '/../../addons/paymenthood/paymenthoodhandler.php';
} catch (\Throwable $e) {
    $paymenthoodRespond(500, [
        'success' => false,
        'error' => 'Bootstrap failed',
        'detail' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $inputRaw = file_get_contents('php://input');
        $input = json_decode($inputRaw, true);
        if (!is_array($input)) {
            $input = [];
        }

        if (!empty($input['logError'])) {
            PaymentHoodHandler::safeLogModuleCall(
                'get_payment_profiles_client_error',
                [
                    'errorType' => $input['errorType'] ?? 'fetch_error',
                    'errorMessage' => $input['errorMessage'] ?? 'Unknown error',
                    'httpStatus' => $input['httpStatus'] ?? null,
                ],
                [
                    'responseText' => $input['responseText'] ?? null,
                    'stack' => $input['stack'] ?? null,
                    'url' => $_SERVER['HTTP_REFERER'] ?? null,
                    'rawInputLength' => is_string($inputRaw) ? strlen($inputRaw) : null,
                ]
            );

            $paymenthoodRespond(200, ['success' => true]);
        }

        $profileId = $input['profileId'] ?? null;
        if ($profileId !== null && $profileId !== '') {
            $_SESSION['paymenthood_profile_id'] = $profileId;
            $paymenthoodRespond(200, ['success' => true]);
        }

        $paymenthoodRespond(400, [
            'success' => false,
            'error' => 'Missing profileId'
        ]);
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_post_error', [], [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], $e->getTraceAsString());

        $paymenthoodRespond(500, [
            'success' => false,
            'error' => 'POST failed',
            'detail' => $e->getMessage(),
        ]);
    }
}

// GET: fetch profiles
try {
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'] ?? null;
    $token = $credentials['token'] ?? null;

    if (empty($appId) || empty($token)) {
        PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_missing_credentials', [], [
            'hasAppId' => !empty($appId),
            'hasToken' => !empty($token),
        ]);
        $paymenthoodRespond(400, [
            'success' => false,
            'error' => 'PaymentHood not configured'
        ]);
    }

    $url = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl() . "/apps/{$appId}/payment-profiles";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        'Accept: application/json',
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_ajax', [
        'appId' => $appId,
        'url' => $url,
    ], [
        'httpCode' => $httpCode,
        'curlError' => $curlError,
        'responseLength' => is_string($response) ? strlen($response) : null,
    ]);

    if ($response === false) {
        $paymenthoodRespond(502, [
            'success' => false,
            'error' => 'Upstream call failed',
            'detail' => $curlError,
        ]);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($response) ? substr($response, 0, 500) : null;
        $paymenthoodRespond($httpCode > 0 ? $httpCode : 502, [
            'success' => false,
            'error' => 'PaymentHood API returned error',
            'httpCode' => $httpCode,
            'details' => $snippet,
        ]);
    }

    $profiles = json_decode($response, true);
    if (!is_array($profiles)) {
        $paymenthoodRespond(502, [
            'success' => false,
            'error' => 'Invalid JSON from PaymentHood API'
        ]);
    }

    $activeProfiles = array_filter($profiles, function ($profile) {
        return isset($profile['isActive']) && $profile['isActive'] === true;
    });

    $paymenthoodRespond(200, [
        'success' => true,
        'profiles' => array_values($activeProfiles),
    ]);
} catch (\Throwable $e) {
    PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_ajax_error', [], [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], $e->getTraceAsString());

    $paymenthoodRespond(500, [
        'success' => false,
        'error' => 'Internal error',
        'detail' => $e->getMessage(),
    ]);
}

