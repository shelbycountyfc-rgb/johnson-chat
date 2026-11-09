<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Prefer the real client IP. X-Forwarded-For is only honored when explicitly
// enabled via $GLOBALS['APP_TRUST_XFF'] = true (e.g. in an optional
// api/config.local.php) because the header is client-spoofable.
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (isset($GLOBALS['APP_TRUST_XFF']) && $GLOBALS['APP_TRUST_XFF']) {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $ips = array_map('trim', explode(',', $forwarded));
        $ip = $ips[0] !== '' ? $ips[0] : $ip;
    }
}

echo json_encode([
    'ip' => $ip,
    'display' => $ip,
]);