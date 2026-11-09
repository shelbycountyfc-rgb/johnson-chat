<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$since = (float) ($_GET['since'] ?? 0);
$roomId = trim($_GET['room_id'] ?? 'general');
$token = trim($_GET['token'] ?? '');
$before = isset($_GET['before']) && $_GET['before'] !== '' ? (float) $_GET['before'] : null;
$limit = 50;

$database = Database::getInstance();

// Optional auth: a valid token lets the server filter out blocked users'
// messages and the caller's own typing entry.
$viewer = '';
if ($token !== '') {
    $tokenRow = app_session_check($database, $token);
    if ($tokenRow) {
        $viewer = $tokenRow['username'];
    }
}

$messages = [];
$hasMore = false;

if ($before !== null) {
    // Backwards pagination: the 50 messages immediately older than $before.
    $stmt = $database->prepare("SELECT id, username, message, room_id, time, timestamp, edited, edit_ts, deleted, reactions, file_token, reply_to
        FROM messages WHERE room_id = ? AND timestamp < ? ORDER BY timestamp DESC LIMIT 51");
    $stmt->execute([$roomId, $before]);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $rows = array_reverse($rows);
} else {
    // Live window: newest 100 new/updated messages (not the oldest 100).
    // One extra row tells the client whether older history exists.
    $stmt = $database->prepare("SELECT id, username, message, room_id, time, timestamp, edited, edit_ts, deleted, reactions, file_token, reply_to
        FROM messages WHERE room_id = ? AND (timestamp > ? OR edit_ts > ?) ORDER BY timestamp DESC LIMIT 101");
    $stmt->execute([$roomId, $since, $since]);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > 100;
    $rows = array_reverse(array_slice($rows, 0, 100));
}

foreach ($rows as $m) {
    if ($viewer !== '' && user_is_blocked($database, $viewer, $m['username'])) {
        continue;
    }
    $messages[] = attach_reply_info($database, massage_public_message($m));
}

// The cursor must cover edits too, otherwise an edited message whose edit_ts
// is newer than the newest message timestamp replays on every poll.
$stmt2 = $database->prepare("SELECT COALESCE(MAX(timestamp), 0) AS mt, COALESCE(MAX(edit_ts), 0) AS me FROM messages WHERE room_id = ?");
$stmt2->execute([$roomId]);
$maxRow = $stmt2->fetch();
$serverTime = max((float) ($maxRow['mt'] ?? 0), (float) ($maxRow['me'] ?? 0), $since);
if ($serverTime <= 0) $serverTime = microtime(true);

$typing = load_typing('public:' . $roomId, $viewer);
$pins = room_pins_for($database, $roomId);

$database->close();

echo json_encode([
    'messages' => $messages,
    'server_time' => $serverTime,
    'typing' => $typing,
    'has_more' => $hasMore,
    'pins' => $pins,
]);
