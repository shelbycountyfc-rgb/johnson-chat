<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Auth helper
function blocks_auth(): ?array
{
    $token = trim(($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['token'] ?? '') : ((json_decode(file_get_contents('php://input'), true) ?? [])['token'] ?? '')));
    if ($token === '') {
        http_response_code(403);
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
    return ['username' => $tokenRow['username'], 'db' => $database];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $auth = blocks_auth();
    $username = $auth['username'];
    $database = $auth['db'];
    $stmt = $database->prepare("SELECT blocked FROM blocks WHERE username = ?");
    $stmt->execute([$username]);
    $list = array_column($stmt->fetchAll(), 'blocked');
    $database->close();
    echo json_encode(['blocked' => $list]);
    exit;
}

require_valid_origin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$auth = blocks_auth();
$username = $auth['username'];
$database = $auth['db'];

$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');
$target = trim($input['target'] ?? '');

if ($action !== 'block' && $action !== 'unblock') {
    http_response_code(400);
    echo json_encode(['error' => 'Action must be block or unblock']);
    $database->close();
    exit;
}
if ($target === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Target required']);
    $database->close();
    exit;
}
if (strtolower($target) === strtolower($username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot block yourself']);
    $database->close();
    exit;
}

// Target must exist
$stmt = $database->prepare("SELECT COUNT(*) AS c FROM users WHERE LOWER(username) = LOWER(?)");
$stmt->execute([$target]);
if (($stmt->fetch()['c'] ?? 0) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    $database->close();
    exit;
}

if (!rate_limit_is_allowed("block_user:" . strtolower($username), 30, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many changes. Please try again later.']);
    $database->close();
    exit;
}
rate_limit_register("block_user:" . strtolower($username), 3600);

if ($action === 'block') {
    $stmt = $database->prepare('INSERT OR IGNORE INTO blocks (username, blocked) VALUES (?, ?)');
    $stmt->execute([$username, $target]);
    $database->close();
    echo json_encode(['success' => true, 'blocked' => true]);
    exit;
}

$stmt = $database->prepare("DELETE FROM blocks WHERE username = ? AND blocked = ?");
$stmt->execute([$username, $target]);
$database->close();
echo json_encode(['success' => true, 'blocked' => false]);