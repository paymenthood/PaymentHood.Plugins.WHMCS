<?php
ini_set('display_errors', 0);
error_reporting(0);

$url = isset($_GET['u']) ? (string)$_GET['u'] : '';
$url = trim($url);

if ($url === '' || strlen($url) > 2048) {
    http_response_code(400);
    exit;
}

if (strpos($url, '//') === 0) {
    $url = 'https:' . $url;
}

$parts = parse_url($url);
if (!$parts || !isset($parts['scheme']) || $parts['scheme'] !== 'https') {
    http_response_code(400);
    exit;
}

$host = strtolower(isset($parts['host']) ? (string)$parts['host'] : '');

$allowed =
    $host === 'phpaymentstorageaccount.blob.core.windows.net'
    || substr($host, -24) === '.blob.core.windows.net';

if (!$allowed) {
    http_response_code(403);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'User-Agent: WHMCS-Icon-Proxy',
        'Accept: image/*'
    ],
]);

$data = curl_exec($ch);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code < 200 || $code >= 300 || !$data) {
    http_response_code(502);
    exit;
}

if (!$type || $type === 'application/octet-stream') {
    $ext = strtolower(pathinfo(isset($parts['path']) ? $parts['path'] : '', PATHINFO_EXTENSION));
    if ($ext === 'png') {
        $type = 'image/png';
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $type = 'image/jpeg';
    } elseif ($ext === 'svg') {
        $type = 'image/svg+xml';
    } else {
        $type = 'image/*';
    }
}

header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

echo $data;
exit;
