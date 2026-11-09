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
$action = trim($input['action'] ?? '');
$messageId = trim($input['message_id'] ?? '');
$kind = trim($input['kind'] ?? 'public');
$roomId = trim($input['room_id'] ?? 'general');
$message = trim($input['message'] ?? '');

if ($action !== 'edit' && $action !== 'delete') {
    http_response_code(400);
    echo json_encode(['error' => 'Action must be edit or delete']);
    exit;
}
if ($messageId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message_id required']);
    exit;
}
if ($action === 'edit') {
    if ($message === '' || mb_strlen($message) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Message must be 1-500 characters']);
        exit;
    }
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
$userLower = strtolower($username);

// Throttle edit/delete churn.
if (!rate_limit_is_allowed("msgact_user:$userLower", 30, 30)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many changes. Please slow down.']);
    $database->close();
    exit;
}
rate_limit_register("msgact_user:$userLower", 30);

if ($kind === 'private') {
    if ($roomId === '' || !is_private_room_member($roomId, $username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a room member']);
        $database->close();
        exit;
    }
    $msgFile = __DIR__ . '/../private_messages/' . basename((string) $roomId) . '.json';
    if (!file_exists($msgFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        $database->close();
        exit;
    }
    $msgs = json_decode(file_get_contents($msgFile), true) ?? [];
    $found = -1;
    foreach ($msgs as $i => $m) {
        if (($m['id'] ?? '') === $messageId) {
            $found = $i;
            break;
        }
    }
    if ($found === -1) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        $database->close();
        exit;
    }
    if (strtolower((string) ($msgs[$found]['username'] ?? '')) !== $userLower) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only edit/delete your own messages']);
        $database->close();
        exit;
    }
    if ($action === 'edit') {
        $msgs[$found]['message'] = $message;
        $msgs[$found]['edited'] = 1;
    } else {
        $msgs[$found]['message'] = '[This message was deleted]';
        $msgs[$found]['deleted'] = 1;
    }
    $msgs[$found]['edit_ts'] = microtime(true);
    file_put_contents($msgFile, json_encode($msgs));
    $database->close();
    echo json_encode(['success' => true]);
    exit;
}

// Public room message (SQLite)
$stmt = $database->prepare("SELECT username FROM messages WHERE id = ? AND room_id = ?");
$stmt->execute([$messageId, $roomId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Message not found']);
    $database->close();
    exit;
}

// Author may edit/delete their own; admins may delete (but not edit) any.
$isAuthor = strtolower($row['username']) === $userLower;
$userRole = 'user';
$stmt = $database->prepare("SELECT role FROM users WHERE username = ?");
$stmt->execute([$username]);
$roleRow = $stmt->fetch();
$userRole = $roleRow['role'] ?? 'user';

if (!$isAuthor && !($action === 'delete' && $userRole === 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only edit/delete your own messages']);
    $database->close();
    exit;
}

$now = microtime(true);
if ($action === 'edit') {
    $stmt = $database->prepare("UPDATE messages SET message = ?, edited = 1, edit_ts = ? WHERE id = ?");
    $stmt->execute([$message, $now, $messageId]);
} else {
    $stmt = $database->prepare("UPDATE messages SET message = '[This message was deleted]', deleted = 1, edited = 0, reactions = '{}', edit_ts = ? WHERE id = ?");
    $stmt->execute([$now, $messageId]);
    $stmt = $database->prepare("DELETE FROM room_pins WHERE room_id = ? AND message_id = ?");
    $stmt->execute([$roomId, $messageId]);
}

$database->close();
echo json_encode(['success' => true]);