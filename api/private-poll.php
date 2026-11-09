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

$roomId = trim($_GET['room_id'] ?? '');
$since = (float) ($_GET['since'] ?? 0);
$token = trim($_GET['token'] ?? '');

if ($roomId === '') {
    echo json_encode(['messages' => [], 'server_time' => microtime(true)]);
    exit;
}

// Authenticate caller via token and verify room membership
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
if (!is_private_room_member($roomId, $tokenRow['username'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a room member']);
    $database->close();
    exit;
}
$remParts = explode('_', $roomId);
$otherUser = '';
foreach ($remParts as $p) {
    if (strtolower($p) !== strtolower($tokenRow['username'])) {
        $otherUser = $p;
        break;
    }
}
$blockedPair = $otherUser !== '' && private_pair_blocked($database, $tokenRow['username'], $otherUser);
if ($blockedPair) {
    // Blocked users cannot view each other's private room contents.
    $database->close();
    echo json_encode(['messages' => [], 'server_time' => microtime(true), 'typing' => [], 'blocked' => true]);
    exit;
}

$msgFile = __DIR__ . '/../private_messages/' . basename($roomId) . '.json';

if (!file_exists($msgFile)) {
    $database->close();
    echo json_encode(['messages' => [], 'server_time' => microtime(true), 'typing' => []]);
    exit;
}

$msgs = json_decode(file_get_contents($msgFile), true) ?? [];

$member = [];
$parts = explode('_', $roomId);
if (count($parts) === 2) {
    $member = [strtolower($parts[0]), strtolower($parts[1])];
}

$new = [];
$maxTs = $since;
foreach ($msgs as $m) {
    // Skip the other user's messages if the caller blocked them.
    $author = strtolower((string) ($m['username'] ?? ''));
    if ($author !== strtolower($tokenRow['username']) && in_array($author, $member, true)) {
        if (user_is_blocked($database, $tokenRow['username'], $author)) {
            continue;
        }
    }
    $ts = (float) ($m['timestamp'] ?? 0);
    $ets = (float) ($m['edit_ts'] ?? 0);
    if ($ts > $since || $ets > $since) {
        $m['edited'] = (int) ($m['edited'] ?? 0);
        $m['deleted'] = (int) ($m['deleted'] ?? 0);
        $m['read'] = (bool) ($m['read'] ?? false);
        $m['reactions'] = is_array($m['reactions'] ?? null) ? $m['reactions'] : (object)[];
        $m = attach_upload_info($m);
        $m = attach_private_reply($m, $msgs);
        $new[] = $m;
    }
    if ($ts > $maxTs) $maxTs = $ts;
}

if ($maxTs <= 0) $maxTs = microtime(true);

$typing = load_typing('private:' . $roomId, $tokenRow['username']);

$database->close();

echo json_encode(['messages' => $new, 'server_time' => $maxTs, 'typing' => $typing]);
