<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$username = trim($_GET['username'] ?? '');
$token = trim($_GET['token'] ?? '');
if ($username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username required']);
    exit;
}

$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow || strtolower($tokenRow['username']) !== strtolower($username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$database->close();

$offlineFile = __DIR__ . '/offline.json';
if (!file_exists($offlineFile)) {
    echo json_encode(['messages' => []]);
    exit;
}

$offline = json_decode(file_get_contents($offlineFile), true) ?? [];
$messages = $offline[$username] ?? [];

echo json_encode(['messages' => $messages]);
