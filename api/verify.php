<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo json_encode(['valid' => false]);
    exit;
}

$database = Database::getInstance();

$tokenData = app_session_check($database, $token);

if ($tokenData) {
    $username = $tokenData['username'];
    $stmt = $database->prepare("SELECT role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $role = $user['role'] ?? 'user';

    $offlineFile = __DIR__ . '/offline.json';
    $offlineNotifications = [];
    if (file_exists($offlineFile)) {
        $offline = json_decode(file_get_contents($offlineFile), true) ?? [];
        if (isset($offline[$username])) {
            $offlineNotifications = $offline[$username];
        }
    }

    echo json_encode(['valid' => true, 'username' => $username, 'role' => $role, 'offline_messages' => $offlineNotifications]);
} else {
    echo json_encode(['valid' => false]);
}

$database->close();
