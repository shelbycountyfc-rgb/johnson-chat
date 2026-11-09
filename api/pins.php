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
$action = trim($input['action'] ?? 'list');
$roomId = trim($input['room_id'] ?? '');
$messageId = trim($input['message_id'] ?? '');

$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

$stmt = $database->prepare('SELECT id FROM rooms WHERE id = ?');
$stmt->execute([$roomId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Room not found']);
    $database->close();
    exit;
}

if ($action === 'list') {
    echo json_encode(['success' => true, 'pins' => room_pins_for($database, $roomId)]);
    $database->close();
    exit;
}

if ($action !== 'pin' && $action !== 'unpin') {
    http_response_code(400);
    echo json_encode(['error' => 'Action must be list, pin, or unpin']);
    $database->close();
    exit;
}

if (!room_can_pin($database, $roomId, $username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the room creator, admins, and moderators can pin messages']);
    $database->close();
    exit;
}

if ($messageId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message_id required']);
    $database->close();
    exit;
}

if ($action === 'unpin') {
    $stmt = $database->prepare('DELETE FROM room_pins WHERE room_id = ? AND message_id = ?');
    $stmt->execute([$roomId, $messageId]);
    echo json_encode(['success' => true, 'pins' => room_pins_for($database, $roomId)]);
    $database->close();
    exit;
}

$stmt = $database->prepare('SELECT id, deleted FROM messages WHERE id = ? AND room_id = ?');
$stmt->execute([$messageId, $roomId]);
$message = $stmt->fetch();
if (!$message || (int) ($message['deleted'] ?? 0) === 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Message not found or deleted']);
    $database->close();
    exit;
}

$stmt = $database->prepare('INSERT OR IGNORE INTO room_pins (room_id, message_id, pinned_by) VALUES (?, ?, ?)');
$stmt->execute([$roomId, $messageId, $username]);
echo json_encode(['success' => true, 'pins' => room_pins_for($database, $roomId)]);
$database->close();
