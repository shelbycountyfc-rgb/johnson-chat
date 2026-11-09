<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/security.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

require_once __DIR__ . '/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if ($token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token required']);
    exit;
}

$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

$offlineFile = __DIR__ . '/offline.json';
if (!file_exists($offlineFile)) {
    echo json_encode(['success' => true]);
    exit;
}

$offline = json_decode(file_get_contents($offlineFile), true) ?? [];

$roomId = trim($input['room_id'] ?? '');

if ($roomId !== '') {
    // Clear specific room notification
    if (isset($offline[$username])) {
        $offline[$username] = array_values(array_filter($offline[$username], function($n) use ($roomId) {
            return $n['room_id'] !== $roomId;
        }));
        if (empty($offline[$username])) {
            unset($offline[$username]);
        }
    }
} else {
    // Clear all for this user
    unset($offline[$username]);
}

file_put_contents($offlineFile, json_encode($offline, JSON_PRETTY_PRINT));
echo json_encode(['success' => true]);
