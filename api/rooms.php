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

$database = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $database->prepare("SELECT id, name, created_by, created_at, last_activity, read_only FROM rooms ORDER BY name ASC");
    $stmt->execute();
    $rooms = $stmt->fetchAll();
    foreach ($rooms as &$r) {
        $r['read_only'] = (int) ($r['read_only'] ?? 0);
    }
    unset($r);

    $database->close();

    echo json_encode(['rooms' => $rooms]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'create';
$token = trim($input['token'] ?? '');

// Verify token via SQLite
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); $database->close(); exit; }
$username = $tokenRow['username'];

// Load user role from SQLite
$stmt = $database->prepare("SELECT role FROM users WHERE username = ?");
$stmt->execute([$username]);
$userRow = $stmt->fetch();
$userRole = $userRow['role'] ?? 'user';

if ($action === 'delete') {
    $room_id = trim($input['room_id'] ?? '');
    if ($room_id === '') { http_response_code(400); echo json_encode(['error' => 'Room ID required']); exit; }
    if ($room_id === 'general') { http_response_code(403); echo json_encode(['error' => 'Cannot delete General room']); exit; }

    // Check if room exists and user has permission
    $stmt = $database->prepare("SELECT created_by FROM rooms WHERE id = ?");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch();

    if (!$room) { http_response_code(404); echo json_encode(['error' => 'Room not found']); exit; }

    // Only creator or admin can delete
    if ($room['created_by'] !== $username && $userRole !== 'admin') {
        http_response_code(403); echo json_encode(['error' => 'Only the room creator or an admin can delete this room']); exit;
    }

    // Delete room
    $stmt = $database->prepare("DELETE FROM rooms WHERE id = ?");
    $stmt->execute([$room_id]);

    // Delete associated messages
    $stmt = $database->prepare("DELETE FROM messages WHERE room_id = ?");
    $stmt->execute([$room_id]);

    // Delete read-cursor state for this room
    $stmt = $database->prepare("DELETE FROM read_state WHERE room_id = ?");
    $stmt->execute([$room_id]);

    $database->close();

    echo json_encode(['success' => true]);
    exit;
}

// Default: create room
$name = trim($input['name'] ?? '');
if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Room name required']); exit; }

// Read-only announcement rooms may only be created by staff.
$readOnly = !empty($input['read_only']);
if ($readOnly) {
    if ($userRole !== 'admin' && $userRole !== 'moderator') {
        http_response_code(403);
        echo json_encode(['error' => 'Only staff can create announcement rooms']);
        $database->close();
        exit;
    }
}

$id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '-', $name)));
if ($id === '') { http_response_code(400); echo json_encode(['error' => 'Invalid room name']); exit; }

// Prevent room-creation flooding per user.
if (!rate_limit_is_allowed("room_create_user:" . strtolower($username), 20, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many rooms created. Please try again later.']);
    exit;
}
rate_limit_register("room_create_user:" . strtolower($username), 3600);

// Check if room already exists
$stmt = $database->prepare("SELECT COUNT(*) as count FROM rooms WHERE id = ?");
$stmt->execute([$id]);
if ($stmt->fetch()['count'] > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Room already exists']);
    $database->close();
    exit;
}

$now = date('Y-m-d H:i:s');
$stmt = $database->prepare("INSERT INTO rooms (id, name, created_by, created_at, last_activity, read_only) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$id, $name, $username, $now, $now, $readOnly ? 1 : 0]);

$database->close();

echo json_encode(['success' => true, 'room' => ['id' => $id, 'name' => $name, 'read_only' => $readOnly ? 1 : 0]]);
