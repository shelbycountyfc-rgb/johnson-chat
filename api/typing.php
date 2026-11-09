<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$kind = trim($input['kind'] ?? 'public');
$roomId = trim($input['room_id'] ?? 'general');

if ($token === '') {
    http_response_code(403);
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

if ($kind === 'private') {
    if ($roomId === '' || !is_private_room_member($roomId, $username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a room member']);
        $database->close();
        exit;
    }
    $key = 'private:' . basename($roomId);
} else {
    $key = 'public:' . trim($roomId);
}

// Throttle heartbeat spam (client sends at most every ~1.5s per user anyway).
if (!rate_limit_is_allowed("typing_user:" . strtolower($username), 60, 30)) {
    http_response_code(429);
    echo json_encode(['error' => 'Slow down']);
    $database->close();
    exit;
}
rate_limit_register("typing_user:" . strtolower($username), 30);

store_typing($key, $username, microtime(true));

$database->close();
echo json_encode(['success' => true]);