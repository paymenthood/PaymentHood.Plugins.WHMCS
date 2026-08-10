<?php
/**
 * Standalone payment-icon proxy.
 *
 * Allowlist, redirect handling and response headers all live in
 * icon-proxy-guard.php so this endpoint cannot drift from the copy embedded in
 * get-payment-profiles.php?proxy=1.
 */

ini_set('display_errors', 0);
error_reporting(0);

// A missing guard file means a partial upload; say so in the log rather than
// dying with a bare fatal.
$guardPath = __DIR__ . '/icon-proxy-guard.php';
if (!is_file($guardPath)) {
    error_log('PaymentHood icon proxy: missing ' . $guardPath
        . ' - re-upload modules/gateways/paymenthood/ in full.');
    http_response_code(500);
    exit;
}
require_once $guardPath;

$requestedUrl = isset($_GET['u']) ? (string) $_GET['u'] : '';
$target = paymenthood_iconProxyValidateUrl($requestedUrl);
if ($target === false) {
    error_log('PaymentHood icon proxy blocked url=' . $requestedUrl);
    http_response_code(403);
    exit;
}

$result = paymenthood_iconProxyFetch($target['url']);
if (!$result['ok']) {
    http_response_code(502);
    exit;
}

$path = parse_url($result['url'], PHP_URL_PATH);
$contentType = paymenthood_iconProxyResolveContentType($result['type'], $path === null ? '' : $path);
if ($contentType === false) {
    http_response_code(502);
    exit;
}

paymenthood_iconProxySendHeaders($contentType, strlen($result['body']));
echo $result['body'];
exit;
