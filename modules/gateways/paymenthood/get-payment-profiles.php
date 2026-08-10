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
error_reporting(0);

// Handle icon proxy mode (GET with ?proxy=1&u=...)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['proxy'])) {
    // Ensure absolutely no output before headers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // icon-proxy-guard.php ships in this same directory and both proxy entry
    // points require it. A missing file here is almost always a partial upload,
    // and without this check it surfaces as a bare fatal with no explanation.
    $guardPath = __DIR__ . '/icon-proxy-guard.php';
    if (!is_file($guardPath)) {
        error_log('PaymentHood icon proxy: missing ' . $guardPath
            . ' - re-upload modules/gateways/paymenthood/ in full.');
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Icon proxy misconfigured';
        exit;
    }
    require_once $guardPath;

    // Validates scheme/host/port/credentials and rebuilds the URL from the
    // parts that were actually checked. See icon-proxy-guard.php.
    $requestedUrl = isset($_GET['u']) ? (string) $_GET['u'] : '';
    $target = paymenthood_iconProxyValidateUrl($requestedUrl);
    if ($target === false) {
        // Logged server-side only. Echoing the rejected host back to the caller
        // would leak it, but with no record at all a blocked icon is impossible
        // to diagnose.
        error_log('PaymentHood icon proxy blocked url=' . $requestedUrl);
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Host not allowed';
        exit;
    }

    // Follows redirects manually, re-running the allowlist on every hop.
    $result = paymenthood_iconProxyFetch($target['url']);

    if (!$result['ok']) {
        error_log(sprintf(
            'PaymentHood icon proxy upstream failure: host=%s, HTTP=%d, error=%s',
            $target['host'],
            $result['status'],
            $result['error'] !== '' ? $result['error'] : 'none'
        ));

        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Upstream failed';
        exit;
    }

    $finalPath = parse_url($result['url'], PHP_URL_PATH);
    $contentType = paymenthood_iconProxyResolveContentType(
        $result['type'],
        $finalPath === null ? '' : $finalPath
    );

    if ($contentType === false) {
        error_log(sprintf(
            'PaymentHood icon proxy rejected content type: host=%s, type=%s',
            $target['host'],
            $result['type']
        ));

        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Unsupported content type';
        exit;
    }

    // Clear any output buffers before sending image
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    paymenthood_iconProxySendHeaders($contentType, strlen($result['body']));
    echo $result['body'];
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

        if (!empty($input['logClient'])) {
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
        $paymenthoodRespond(400, [
            'success' => false,
            'error' => 'PaymentHood not configured'
        ]);
    }
    
    $url = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl() . "/apps/{$appId}/payment-profiles/payment-checkout-methods";

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

    if ($response === false) {
        PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_curl_failed', [], [
            'error' => 'cURL request failed',
            'curlError' => $curlError,
        ]);
        $paymenthoodRespond(502, [
            'success' => false,
            'error' => 'Upstream call failed',
            'detail' => $curlError,
        ]);
    }

    if ($response !== false && PaymentHoodHandler::isAppInactiveError($response)) {
        PaymentHoodHandler::markAppInactive($appId, $response);
        PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_app_inactive', [
            'appId' => $appId,
            'url' => $url,
        ], [
            'httpCode' => $httpCode,
            'responseSnippet' => substr((string) $response, 0, 500),
        ]);

        $paymenthoodRespond(503, [
            'success' => false,
            'appInactive' => true,
            'error' => PaymentHoodHandler::getCustomerAppInactiveMessage(),
        ]);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        PaymentHoodHandler::clearAppInactiveState();
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($response) ? substr($response, 0, 500) : null;
        PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_api_error', [], [
            'error' => 'API returned error',
            'httpCode' => $httpCode,
            'responseSnippet' => $snippet,
        ]);
        $paymenthoodRespond($httpCode > 0 ? $httpCode : 502, [
            'success' => false,
            'error' => 'PaymentHood API returned error',
            'httpCode' => $httpCode,
            'details' => $snippet,
        ]);
    }

    $checkoutMethods = json_decode($response, true);
    
    if (!is_array($checkoutMethods)) {
        PaymentHoodHandler::safeLogModuleCall('get_payment_profiles_invalid_json', [], [
            'error' => 'Invalid JSON response',
            'responsePreview' => substr($response, 0, 200),
        ]);
        $paymenthoodRespond(502, [
            'success' => false,
            'error' => 'Invalid JSON from PaymentHood API'
        ]);
    }
    
    // Flatten the response based on checkoutMethod
    $activeProfiles = [];
    foreach ($checkoutMethods as $index => $checkoutMethodGroup) {
        $checkoutMethod = $checkoutMethodGroup['checkoutMethod'] ?? '';
        
        if ($checkoutMethod === 'CreditCard') {
            // Extract card icons from paymentCheckoutMethodItems
            $items = $checkoutMethodGroup['paymentCheckoutMethodItems'] ?? [];
            
            $firstItem = !empty($items) ? $items[0] : [];
            
            // API returns iconUri1 and iconUri2 directly on the item
            // (PaymentCheckoutMethodItem.iconUri1 / .iconUri2).
            $iconUri1 = $firstItem['iconUri1'] ?? null;
            $iconUri2 = $firstItem['iconUri2'] ?? null;

            // With no icon URI the card row renders without an <img> at all,
            // which is indistinguishable from a broken proxy at the browser.
            // Record which it is.
            if (empty($iconUri1) && empty($iconUri2)) {
                PaymentHoodHandler::safeLogModuleCall('card_icon_missing_from_api', [
                    'checkoutMethod' => 'CreditCard',
                ], [
                    'itemCount' => count($items),
                    'itemKeys' => $firstItem ? array_keys($firstItem) : [],
                ]);
            }
            
            $isSupportSubscription = $firstItem['isSupportSubscription'] ?? false;
            $isSupportSinglePayment = $firstItem['isSupportSinglePayment'] ?? true;
            
            // Add credit card icon with light and dark mode support from API
            $activeProfiles[] = [
                'checkoutMethod' => 'CreditCard',
                'paymentProfileId' => 'creditcard',
                'paymentProfileName' => 'Card',
                'currency' => null,
                'isActive' => true,
                'paymentProvider' => [
                    'provider' => 'CreditCard',
                    'iconUri1' => $iconUri1,
                    'iconUri2' => $iconUri2,
                ],
                'isSupportSubscription' => $isSupportSubscription,
                'isSupportSinglePayment' => $isSupportSinglePayment,
            ];
            
        } elseif ($checkoutMethod === 'ProviderHostedPage') {
            // Loop through all paymentCheckoutMethodItems
            $items = $checkoutMethodGroup['paymentCheckoutMethodItems'] ?? [];
            
            foreach ($items as $itemIndex => $item) {
                $profile = $item['paymentProfile'] ?? null;
                if ($profile && isset($profile['isActive']) && $profile['isActive'] === true) {
                    // Add additional fields from the item
                    $profile['isSupportSubscription'] = $item['isSupportSubscription'] ?? false;
                    $profile['isSupportSinglePayment'] = $item['isSupportSinglePayment'] ?? true;
                    $profile['paymentMethodAddMode'] = $item['paymentMethodAddMode'] ?? null;
                    $profile['checkoutMethod'] = $checkoutMethod;
                    $activeProfiles[] = $profile;
                }
            }
        }
    }

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

