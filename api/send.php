<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/pushlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$message = trim($input['message'] ?? '');
$roomId = trim($input['room_id'] ?? 'general');
$token = trim($input['token'] ?? '');
$replyTo = trim($input['reply_to'] ?? '');

if ($username === '' || $message === '') { http_response_code(400); echo json_encode(['error' => 'Username and message are required']); exit; }
if (mb_strlen($username) > 20 || mb_strlen($message) > 500) { http_response_code(400); echo json_encode(['error' => 'Username or message too long']); exit; }

$database = Database::getInstance();

if ($token === '') { http_response_code(403); echo json_encode(['error' => 'Token required']); $database->close(); exit; }
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); $database->close(); exit; }
if (strtolower($tokenRow['username']) !== strtolower($username)) { http_response_code(403); echo json_encode(['error' => 'Token does not match username']); $database->close(); exit; }

// Ensure the room exists
$stmt = $database->prepare("SELECT COUNT(*) AS c, MAX(read_only) AS read_only FROM rooms WHERE id = ?");
$stmt->execute([$roomId]);
$roomRow = $stmt->fetch();
if ((int) $roomRow['c'] === 0) { http_response_code(404); echo json_encode(['error' => 'Room not found']); $database->close(); exit; }

// Read-only announcement rooms: only admins/moderators may post.
if ((int) ($roomRow['read_only'] ?? 0) === 1) {
    $stmt = $database->prepare("SELECT role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $roleRow = $stmt->fetch();
    $role = $roleRow['role'] ?? 'user';
    if ($role !== 'admin' && $role !== 'moderator') {
        http_response_code(403);
        echo json_encode(['error' => 'This room is read-only. Only staff can post here.']);
        $database->close();
        exit;
    }
}

// Check if user exists and is not muted/banned
$stmt = $database->prepare("SELECT muted_until, banned_until FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user) {
    $muted = $user['muted_until'] ?? null;
    if ($muted !== null) {
        if ($muted === 'indefinite' || strtotime($muted) > time()) {
            http_response_code(403);
            echo json_encode(['error' => 'You are muted']);
            $database->close();
            exit;
        }
    }
    $banned = $user['banned_until'] ?? null;
    if ($banned !== null) {
        if ($banned === 'indefinite' || strtotime($banned) > time()) {
            http_response_code(403);
            echo json_encode(['error' => 'You are banned']);
            $database->close();
            exit;
        }
    }
} else {
    http_response_code(403);
    echo json_encode(['error' => 'User not found']);
    $database->close();
    exit;
}

// Throttle: block message flooding per user and per IP.
$uidLower = strtolower($username);
$srcIp = rate_limit_client_ip();
if (!rate_limit_is_allowed("msg_user:$uidLower", 30, 30) || !rate_limit_is_allowed("msg_ip:$srcIp", 120, 30)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many messages. Please slow down.']);
    $database->close();
    exit;
}
rate_limit_register("msg_user:$uidLower", 30);
rate_limit_register("msg_ip:$srcIp", 30);

if ($replyTo !== '') {
    $stmt = $database->prepare("SELECT id FROM messages WHERE id = ? AND room_id = ? AND deleted = 0 LIMIT 1");
    $stmt->execute([$replyTo, $roomId]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Reply target not found in this room']);
        $database->close();
        exit;
    }
}

$newMessageId = uniqid('', true);
$stmt = $database->prepare("INSERT INTO messages (id, username, message, room_id, time, timestamp, edited, edit_ts, deleted, reactions, file_token, reply_to) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, '{}', '', ?)");
$stmt->execute([
    $newMessageId,
    $username,
    $message,
    $roomId,
    date('H:i'),
    microtime(true),
    $replyTo
]);

// Scan for prohibited content
require_once __DIR__ . '/moderation.php';
$flagged = scanMessage($message, $username, $roomId, 'public');

// Update room last_activity
$stmt = $database->prepare("UPDATE rooms SET last_activity = ? WHERE id = ?");
$stmt->execute([date('Y-m-d H:i:s'), $roomId]);

$database->close();

try {
    push_notify_mentions($roomId, $message, $username);
} catch (Throwable $e) {
    // A push failure must never fail the message send.
}

echo json_encode(['success' => true, 'message' => [
    'id' => $newMessageId,
    'username' => $username,
    'message' => $message,
    'time' => date('H:i'),
    'timestamp' => microtime(true),
    'edited' => 0,
    'deleted' => 0,
    'reactions' => new stdClass(),
    'reply_to' => $replyTo,
]]);
