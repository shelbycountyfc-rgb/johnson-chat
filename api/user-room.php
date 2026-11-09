<?php
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/database.php';
$database = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_origin();
    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    $room_id = trim($input['room_id'] ?? '');

$row = app_session_check($database, $token);
    if (!$row) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); exit; }

    $username = $row['username'];

    $stmt = $database->prepare("INSERT OR REPLACE INTO user_rooms (username, room_id, joined_at) VALUES (?, ?, datetime('now'))");
    $stmt->execute([$username, $room_id]);

    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $username = trim($_GET['username'] ?? '');
    if ($username) {
        $stmt = $database->prepare("SELECT room_id FROM user_rooms WHERE username = ? ORDER BY joined_at DESC LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        echo json_encode(['room' => $row ? $row['room_id'] : null]);
    } else {
        echo json_encode(['room' => null]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
