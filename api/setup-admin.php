<?php
error_reporting(0);
header('Content-Type: application/json');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

require_valid_origin();

// Rate limit admin-creation attempts per IP so a bystander cannot race to
// claim the admin account on an unconfigured install.
$ip = rate_limit_client_ip();
if (!rate_limit_is_allowed("admin_create_ip:$ip", 10, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Please try again later.']);
    exit;
}
rate_limit_register("admin_create_ip:$ip", 3600);

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit;
}

if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
    http_response_code(400);
    echo json_encode(['error' => 'Username must be 3-20 characters']);
    exit;
}

if (mb_strlen($password) < 4) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 4 characters']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Username can only contain letters, numbers, and underscores']);
    exit;
}

try {
    $database = Database::getInstance();

    // Refuse if an admin already exists in the active store (SQLite).
    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1");
    $stmt->execute();
    if ((int) $stmt->fetch()['c'] > 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin already configured']);
        $database->close();
        exit;
    }

    // Username must be free in SQLite.
    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM users WHERE LOWER(username) = LOWER(?)");
    $stmt->execute([$username]);
    if ((int) $stmt->fetch()['c'] > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Username already taken']);
        $database->close();
        exit;
    }

    $stmt = $database->prepare("INSERT INTO users (full_name, username, password, is_active, role, created_at) VALUES ('Admin', ?, ?, 1, 'admin', datetime('now'))");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

    $database->close();
    echo json_encode(['success' => true, 'message' => 'Admin account created. You can now log in.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}