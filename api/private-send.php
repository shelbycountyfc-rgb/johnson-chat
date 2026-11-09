<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/chatlib.php';
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
$roomId = trim($input['room_id'] ?? '');
$username = trim($input['username'] ?? '');
$message = trim($input['message'] ?? '');
$token = trim($input['token'] ?? '');
$replyTo = trim($input['reply_to'] ?? '');

if ($roomId === '' || $username === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'room_id, username, and message required']);
    exit;
}

// Authenticate sender via token and verify room membership
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
if (strtolower($tokenRow['username']) !== strtolower($username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token does not match username']);
    $database->close();
    exit;
}
if (!is_private_room_member($roomId, $username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a room member']);
    $database->close();
    exit;
}

// Blocking: if either party blocked the other, do not relay messages.
$parts = explode('_', $roomId);
$otherUser = '';
foreach ($parts as $p) {
    if (strtolower($p) !== strtolower($username)) {
        $otherUser = $p;
        break;
    }
}
if ($otherUser !== '' && private_pair_blocked($database, $username, $otherUser)) {
    http_response_code(403);
    echo json_encode(['error' => 'Messaging unavailable']);
    $database->close();
    exit;
}
$database->close();

// Throttle per user to prevent flooding.
$uidLower = strtolower($username);
$srcIp = rate_limit_client_ip();
if (!rate_limit_is_allowed("msg_user:$uidLower", 30, 30) || !rate_limit_is_allowed("msg_ip:$srcIp", 120, 30)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many messages. Please slow down.']);
    exit;
}
rate_limit_register("msg_user:$uidLower", 30);
rate_limit_register("msg_ip:$srcIp", 30);

// Store private messages in private_messages/ directory
$msgDir = __DIR__ . '/../private_messages';
if (!is_dir($msgDir)) mkdir($msgDir, 0777, true);

$msgFile = "$msgDir/" . basename($roomId) . ".json";
$msgs = file_exists($msgFile) ? (json_decode(file_get_contents($msgFile), true) ?? []) : [];

if ($replyTo !== '') {
    $replyFound = false;
    foreach ($msgs as $existing) {
        if ((string) ($existing['id'] ?? '') === $replyTo && (int) ($existing['deleted'] ?? 0) === 0) {
            $replyFound = true;
            break;
        }
    }
    if (!$replyFound) {
        http_response_code(400);
        echo json_encode(['error' => 'Reply target not found in this chat']);
        exit;
    }
}

$msgs[] = [
    'id' => uniqid('', true),
    'username' => $username,
    'message' => $message,
    'time' => date('H:i'),
    'timestamp' => microtime(true),
    'edited' => 0,
    'edit_ts' => 0,
    'deleted' => 0,
    'reactions' => [],
    'read' => false,
    'reply_to' => $replyTo,
];

file_put_contents($msgFile, json_encode($msgs));

try {
    if ($otherUser !== '') {
        push_notify_user($otherUser, [
            'title' => 'JohnsonChat private message',
            'body' => $username . ': ' . mb_substr($message, 0, 120),
            'url' => './messnger.html',
            'tag' => 'private-' . $roomId,
        ]);
    }
} catch (Throwable $e) {
    // Push failure must not fail the message send.
}

echo json_encode(['success' => true]);
