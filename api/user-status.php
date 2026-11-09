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
$username = trim($input['username'] ?? '');

if ($username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username required']);
    exit;
}

$database = Database::getInstance();

$stmt = $database->prepare("SELECT muted_until, banned_until, role FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user) {
    $muted = $user['muted_until'] ?? null;
    $banned = $user['banned_until'] ?? null;

    if ($muted !== null && $muted !== 'indefinite' && strtotime($muted) < time()) {
        $muted = null;
    }
    if ($banned !== null && $banned !== 'indefinite' && strtotime($banned) < time()) {
        $banned = null;
    }

    echo json_encode([
        'muted_until' => $muted,
        'banned_until' => $banned,
        'role' => $user['role'] ?? 'user',
    ]);
} else {
    echo json_encode(['muted_until' => null, 'banned_until' => null, 'role' => 'user']);
}

$database->close();
