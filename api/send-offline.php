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
$token = trim($input['token'] ?? '');
$target = trim($input['target'] ?? '');
$message = trim($input['message'] ?? '');

if ($token === '' || $target === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token, target, and message required']);
    exit;
}
if (mb_strlen($message) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too long (max 500)']);
    exit;
}

// Verify token via SQLite
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

// The recipient must exist, and blocks apply to offline messages too.
$stmt = $database->prepare("SELECT COUNT(*) AS c FROM users WHERE LOWER(username) = LOWER(?)");
$stmt->execute([$target]);
if ((int) ($stmt->fetch()['c'] ?? 0) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    $database->close();
    exit;
}
if (private_pair_blocked($database, $username, $target)) {
    http_response_code(403);
    echo json_encode(['error' => 'Messaging unavailable']);
    $database->close();
    exit;
}

// Throttle per user to prevent flooding.
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

if ($target === $username) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot message yourself']);
    exit;
}

// Build room key
$myLower = strtolower($username);
$targetLower = strtolower($target);
$roomKey = [min($myLower, $targetLower), max($myLower, $targetLower)];
$roomId = $roomKey[0] . '_' . $roomKey[1];

// Create or get room in private.json
$privateFile = __DIR__ . '/private.json';
$private = file_exists($privateFile) ? (json_decode(file_get_contents($privateFile), true) ?? []) : [];

if (!isset($private['rooms'][$roomId])) {
    $private['rooms'][$roomId] = [
        'users' => [$username, $target],
        'created_at' => date('Y-m-d H:i:s'),
    ];
}
// Also remove any pending requests between these users
$private['pending'] = array_values(array_filter($private['pending'] ?? [], function($p) use ($username, $target) {
    return !(($p['from'] === $username && $p['to'] === $target) || ($p['from'] === $target && $p['to'] === $username));
}));
file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));

// Store the message
$msgDir = __DIR__ . '/../private_messages';
if (!is_dir($msgDir)) mkdir($msgDir, 0777, true);

$msgFile = "$msgDir/$roomId.json";
$msgs = file_exists($msgFile) ? (json_decode(file_get_contents($msgFile), true) ?? []) : [];

$msgEntry = [
    'id' => uniqid('', true),
    'username' => $username,
    'message' => $message,
    'time' => date('H:i'),
    'timestamp' => microtime(true),
];
$msgs[] = $msgEntry;
file_put_contents($msgFile, json_encode($msgs));

// Record offline notification
$offlineFile = __DIR__ . '/offline.json';
$offline = file_exists($offlineFile) ? (json_decode(file_get_contents($offlineFile), true) ?? []) : [];

if (!isset($offline[$target])) {
    $offline[$target] = [];
}
$snippet = mb_strlen($message) > 60 ? mb_substr($message, 0, 60) . '...' : $message;
$offline[$target][] = [
    'from' => $username,
    'room_id' => $roomId,
    'message_snippet' => $snippet,
    'timestamp' => date('Y-m-d H:i:s'),
];
file_put_contents($offlineFile, json_encode($offline, JSON_PRETTY_PRINT));

try {
    push_notify_user($target, [
        'title' => 'JohnsonChat private message',
        'body' => $username . ': ' . $snippet,
        'url' => './messnger.html',
        'tag' => 'private-' . $roomId,
    ]);
} catch (Throwable $e) {
    // Push failure must not fail the offline message.
}

echo json_encode([
    'success' => true,
    'message' => 'Message sent. ' . ($target) . ' will see it when they log in.',
    'room_id' => $roomId,
    'offline' => true,
]);
