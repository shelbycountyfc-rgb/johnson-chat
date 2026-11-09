<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
header('Content-Type: application/json');
echo json_encode([
    'session_check_defined' => function_exists('app_session_check'),
    'token_sql_defined' => function_exists('app_token_sql'),
    'php_version' => PHP_VERSION,
    'curl_loaded' => extension_loaded('curl'),
]);
