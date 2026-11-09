<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$friendsFile = __DIR__ . '/friends.json';
$friends = [];
if (file_exists($friendsFile)) {
    $friends = json_decode(file_get_contents($friendsFile), true) ?? [];
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: list friends for a user
if ($method === 'GET') {
    $username = trim($_GET['username'] ?? '');
    if ($username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Username required']);
        exit;
    }
    $list = $friends[$username] ?? [];
    // Return online status for each friend
    $activeFile = __DIR__ . '/active_users.json';
    $active = file_exists($activeFile) ? (json_decode(file_get_contents($activeFile), true) ?? []) : [];
    $result = [];

    // Optional token: unread counts + last-message previews are only exposed
    // to the account owner.
    $canSeeDetails = false;
    $token = trim($_GET['token'] ?? '');
    if ($token !== '' && $username !== '') {
        $database = Database::getInstance();
        $tokenRow = app_session_check($database, $token);
        // Only the account owner may see unread counts and message previews.
        if ($tokenRow && strtolower((string) $tokenRow['username']) === strtolower($username)) {
            $canSeeDetails = true;
        }
    }

    $msgDir = __DIR__ . '/../private_messages';
    foreach ($list as $f) {
        $item = [
            'username' => $f,
            'online' => in_array($f, $active),
        ];
        if ($canSeeDetails) {
            $myLower = strtolower($username);
            $fLower = strtolower($f);
            $roomId = [min($myLower, $fLower), max($myLower, $fLower)];
            $roomId = $roomId[0] . '_' . $roomId[1];
            $item['room_id'] = $roomId;
            $item['unread'] = 0;
            $item['preview'] = '';
            $msgFile = "$msgDir/" . $roomId . '.json';
            if (file_exists($msgFile)) {
                $msgs = json_decode(file_get_contents($msgFile), true) ?? [];
                if (is_array($msgs)) {
                    $unread = 0;
                    $preview = '';
                    foreach ($msgs as $m) {
                        if (strtolower((string) ($m['username'] ?? '')) === $fLower && empty($m['read'])) {
                            $unread++;
                        }
                        if (empty($m['deleted'])) {
                            $preview = (string) ($m['message'] ?? '');
                        }
                    }
                    $item['unread'] = $unread;
                    $item['preview'] = $preview;
                }
            }
        }
        $result[] = $item;
    }
    if (isset($database)) $database->close();
    echo json_encode(['friends' => $result]);
    exit;
}

// POST/DELETE require auth via token
require_valid_origin();
$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
if ($token === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Token required']);
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
$friend = trim($input['friend'] ?? '');

if ($method === 'POST') {
    if ($friend === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Friend username required']);
        $database->close();
        exit;
    }
    if ($friend === $username) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot add yourself']);
        $database->close();
        exit;
    }
    // Verify friend user exists in SQLite
    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM users WHERE LOWER(username) = LOWER(?)");
    $stmt->execute([$friend]);
    if (($stmt->fetch()['c'] ?? 0) === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        $database->close();
        exit;
    }
    if (!isset($friends[$username])) $friends[$username] = [];
    if (in_array($friend, $friends[$username])) {
        echo json_encode(['success' => true, 'message' => 'Already friends', 'already' => true]);
        $database->close();
        exit;
    }
    if (count($friends[$username]) >= 500) {
        http_response_code(429);
        echo json_encode(['error' => 'Friend list is full']);
        $database->close();
        exit;
    }
    // Throttle adds per user to prevent list-flooding.
    if (!rate_limit_is_allowed("friend_add_user:" . strtolower($username), 60, 3600)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many friend requests. Please try again later.']);
        $database->close();
        exit;
    }
    rate_limit_register("friend_add_user:" . strtolower($username), 3600);
    $friends[$username][] = $friend;
    file_put_contents($friendsFile, json_encode($friends, JSON_PRETTY_PRINT));
    $database->close();
    echo json_encode(['success' => true, 'message' => 'Friend added']);
    exit;
}

if ($method === 'DELETE') {
    if ($friend === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Friend username required']);
        $database->close();
        exit;
    }
    if (!isset($friends[$username])) {
        http_response_code(404);
        echo json_encode(['error' => 'No friends list']);
        $database->close();
        exit;
    }
    $idx = array_search($friend, $friends[$username]);
    if ($idx === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Friend not found']);
        $database->close();
        exit;
    }
    array_splice($friends[$username], $idx, 1);
    file_put_contents($friendsFile, json_encode($friends, JSON_PRETTY_PRINT));
    $database->close();
    echo json_encode(['success' => true, 'message' => 'Friend removed']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
