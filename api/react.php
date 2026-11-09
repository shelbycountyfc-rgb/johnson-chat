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
$messageId = trim($input['message_id'] ?? '');
$emoji = trim($input['emoji'] ?? '');
$kind = trim($input['kind'] ?? 'public');
$roomId = trim($input['room_id'] ?? 'general');

if ($token === '' || $messageId === '' || $emoji === '') {
    http_response_code(400);
    echo json_encode(['error' => 'token, message_id, and emoji required']);
    exit;
}

// Written as code points so the list cannot be corrupted by file encoding.
$allowed = ["\u{1F44D}", "\u{2764}\u{FE0F}", "\u{1F602}", "\u{1F62E}", "\u{1F622}", "\u{1F525}"];
if (!in_array($emoji, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Emoji not allowed']);
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

// Throttle reaction churn per user.
if (!rate_limit_is_allowed("react_user:" . strtolower($username), 40, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many reactions. Please slow down.']);
    $database->close();
    exit;
}
rate_limit_register("react_user:" . strtolower($username), 60);

if ($kind === 'private') {
    // Only members of the private room may react.
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
    $found = false;
    foreach ($msgs as $i => $m) {
        if (($m['id'] ?? '') === $messageId) {
            $found = true;
            $rx = $m['reactions'] ?? [];
            if (!is_array($rx)) $rx = [];
            if (isset($rx[$emoji]) && in_array($username, $rx[$emoji], true)) {
                $rx[$emoji] = array_values(array_filter($rx[$emoji], fn($u) => $u !== $username));
                if (empty($rx[$emoji])) unset($rx[$emoji]);
            } else {
                $rx[$emoji][] = $username;
            }
            $msgs[$i]['reactions'] = $rx;
            $msgs[$i]['edit_ts'] = microtime(true);
            file_put_contents($msgFile, json_encode($msgs));
            break;
        }
    }
    $database->close();
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        exit;
    }
    echo json_encode(['success' => true, 'reactions' => $msgs[$i]['reactions'] ?? []]);
    exit;
}

// Public room message
$stmt = $database->prepare("SELECT reactions FROM messages WHERE id = ? AND room_id = ?");
$stmt->execute([$messageId, $roomId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Message not found']);
    $database->close();
    exit;
}

$rx = json_decode((string) ($row['reactions'] ?? '{}'), true);
if (!is_array($rx)) $rx = [];
if (isset($rx[$emoji]) && in_array($username, $rx[$emoji], true)) {
    $rx[$emoji] = array_values(array_filter($rx[$emoji], fn($u) => $u !== $username));
    if (empty($rx[$emoji])) unset($rx[$emoji]);
} else {
    $rx[$emoji][] = $username;
}

$stmt = $database->prepare("UPDATE messages SET reactions = ?, edit_ts = ? WHERE id = ?");
$stmt->execute([json_encode($rx), microtime(true), $messageId]);

$database->close();

echo json_encode(['success' => true, 'reactions' => $rx]);
