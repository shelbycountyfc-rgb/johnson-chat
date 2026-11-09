<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$database = Database::getInstance();

// Authenticate the caller
$token = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? trim($_GET['token'] ?? '')
    : trim((json_decode(file_get_contents('php://input'), true) ?? [])['token'] ?? '');

if ($token === '') {
    http_response_code(403);
    echo json_encode(['error' => 'Token required']);
    $database->close();
    exit;
}
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $scope = trim($input['scope'] ?? 'room');
    $roomId = trim($input['room_id'] ?? '');

    if ($scope === 'private') {
        // Mark all messages from the other user in this private room as read.
        $msgFile = __DIR__ . '/../private_messages/' . basename((string) $roomId) . '.json';
        if ($roomId !== '' && file_exists($msgFile)) {
            $msgs = json_decode(file_get_contents($msgFile), true) ?? [];
            $changed = false;
            foreach ($msgs as $i => $m) {
                if (strtolower((string) ($m['username'] ?? '')) !== strtolower($username) && empty($m['read'])) {
                    $msgs[$i]['read'] = true;
                    $changed = true;
                }
            }
            if ($changed) {
                file_put_contents($msgFile, json_encode($msgs));
            }
        }
        $now = microtime(true);
        $stmt = $database->prepare("INSERT INTO read_state (username, room_id, last_read) VALUES (?, ?, ?)
            ON CONFLICT(username, room_id) DO UPDATE SET last_read = excluded.last_read");
        $stmt->execute([$username, 'pvt:' . $roomId, $now]);
        $database->close();
        echo json_encode(['success' => true]);
        exit;
    }

    // Public room: advance last-read cursor.
    $now = microtime(true);
    $stmt = $database->prepare("INSERT INTO read_state (username, room_id, last_read) VALUES (?, ?, ?)
        ON CONFLICT(username, room_id) DO UPDATE SET last_read = excluded.last_read");
    $stmt->execute([$username, $roomId, $now]);
    $database->close();
    echo json_encode(['success' => true]);
    exit;
}

// GET: per-room unread counts + last message previews.
$stmt = $database->prepare("SELECT id FROM rooms");
$stmt->execute();
$rooms = $stmt->fetchAll();

$out = [];
foreach ($rooms as $room) {
    $roomId = $room['id'];
    $stmt = $database->prepare("SELECT last_read FROM read_state WHERE username = ? AND room_id = ?");
    $stmt->execute([$username, $roomId]);
    $rs = $stmt->fetch();
    $lastRead = $rs ? (float) $rs['last_read'] : 0;

    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM messages WHERE room_id = ? AND timestamp > ? AND deleted = 0");
    $stmt->execute([$roomId, $lastRead]);
    $unread = (int) $stmt->fetch()['c'];

    $preview = '';
    $lastTs = 0;
    $stmt = $database->prepare("SELECT message, timestamp FROM messages WHERE room_id = ? AND deleted = 0 ORDER BY timestamp DESC LIMIT 1");
    $stmt->execute([$roomId]);
    $last = $stmt->fetch();
    if ($last) {
        $preview = (string) $last['message'];
        $lastTs = (float) $last['timestamp'];
    }

    $out[] = [
        'room_id' => $roomId,
        'unread' => $unread,
        'preview' => $preview,
        'last_ts' => $lastTs,
    ];
}

$database->close();
echo json_encode(['rooms' => $out]);