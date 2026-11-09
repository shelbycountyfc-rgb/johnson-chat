<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$q = trim($_GET['q'] ?? '');
$roomId = trim($_GET['room_id'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($q === '') {
    echo json_encode(['public' => [], 'private' => []]);
    exit;
}
if (mb_strlen($q) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Search too long']);
    exit;
}

$database = Database::getInstance();

// Optional auth: enables block-aware results and unlocks private chats.
$viewer = '';
if ($token !== '') {
    $tokenRow = app_session_check($database, $token);
    if ($tokenRow) {
        $viewer = $tokenRow['username'];
    }
}

$like = '%' . $q . '%';

// Public room search.
$public = [];
try {
    $sql = "SELECT id, username, message, room_id, time, timestamp, edited, deleted, reactions, file_token
            FROM messages
            WHERE message LIKE ? ESCAPE '\\' AND deleted = 0";
    $params = [$like];
    if ($roomId !== '') {
        $sql .= " AND room_id = ?";
        $params[] = $roomId;
    }
    $sql .= " ORDER BY timestamp DESC LIMIT 50";
    $stmt = $database->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $m) {
        if ($viewer !== '' && user_is_blocked($database, $viewer, $m['username'])) {
            continue;
        }
        $public[] = massage_public_message($m);
    }
} catch (Throwable $e) {}

// Private chat search. Results are only ever returned to an authenticated
// member of that specific room, so a guessed room id or an anonymous request
// can never read someone else's private history.
$private = [];
if ($viewer !== '') {
    try {
        $msgDir = __DIR__ . '/../private_messages';
        if (is_dir($msgDir)) {
            $needle = mb_strtolower($q);
            foreach (glob("$msgDir/*.json") as $f) {
                $roomIdF = basename($f, '.json');
                if (!is_private_room_member($roomIdF, $viewer)) {
                    continue;
                }
                $msgs = json_decode(file_get_contents($f), true) ?? [];
                if (!is_array($msgs)) continue;
                foreach ($msgs as $m) {
                    if (!empty($m['deleted'])) continue;
                    if (mb_strpos(mb_strtolower((string) ($m['message'] ?? '')), $needle) === false) {
                        continue;
                    }
                    if (user_is_blocked($database, $viewer, (string) ($m['username'] ?? ''))) {
                        continue;
                    }
                    $private[] = [
                        'id' => $m['id'] ?? '',
                        'username' => $m['username'] ?? '',
                        'message' => $m['message'] ?? '',
                        'room_id' => $roomIdF,
                        'time' => $m['time'] ?? '',
                        'timestamp' => (float) ($m['timestamp'] ?? 0),
                        'edited' => (int) ($m['edited'] ?? 0),
                        'deleted' => (int) ($m['deleted'] ?? 0),
                        'reactions' => is_array($m['reactions'] ?? null) ? $m['reactions'] : [],
                        'private' => true,
                    ];
                    if (count($private) >= 50) {
                        break 2;
                    }
                }
            }
        }
    } catch (Throwable $e) {}
}

$database->close();
echo json_encode(['public' => $public, 'private' => $private]);
