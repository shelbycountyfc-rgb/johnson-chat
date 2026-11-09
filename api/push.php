<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/webpush.php';
error_reporting(0);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') !== 'key') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }
    try {
        echo json_encode(['publicKey' => wp_public_key()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Push is not configured']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$action = trim($input['action'] ?? '');
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

if ($action === 'subscribe') {
    $sub = $input['subscription'] ?? [];
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
    $auth = (string) ($sub['keys']['auth'] ?? '');
    if (!preg_match('#^https://#i', $endpoint) || $p256dh === '' || $auth === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid subscription']);
        $database->close();
        exit;
    }
    $stmt = $database->prepare('INSERT INTO push_subscriptions (username, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
        ON CONFLICT(username, endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth');
    $stmt->execute([$username, $endpoint, $p256dh, $auth]);
    echo json_encode(['success' => true]);
    $database->close();
    exit;
}

if ($action === 'unsubscribe') {
    $endpoint = (string) ($input['endpoint'] ?? '');
    $stmt = $database->prepare('DELETE FROM push_subscriptions WHERE username = ? AND endpoint = ?');
    $stmt->execute([$username, $endpoint]);
    echo json_encode(['success' => true]);
    $database->close();
    exit;
}

if ($action === 'test') {
    require_once __DIR__ . '/pushlib.php';
    $database->close();
    $sent = push_notify_user($username, [
        'title' => 'JohnsonChat',
        'body' => 'Push notifications are working.',
        'url' => './messnger.html',
        'tag' => 'test',
    ]);
    echo json_encode(['success' => true, 'sent' => $sent]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
$database->close();
