<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password required']);
    exit;
}

// Rate limiting: lock out per-IP and per-account after repeated failures.
$ip = rate_limit_client_ip();
if (!rate_limit_is_allowed("login_ip:$ip", 30, 900) || !rate_limit_is_allowed("login_user:" . strtolower($username), 5, 900)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Please try again later.']);
    exit;
}

$database = Database::getInstance();

$stmt = $database->prepare("SELECT username, password, role, is_active, banned_until FROM users WHERE LOWER(username) = LOWER(?)");
$stmt->execute([$username]);
$user = $stmt->fetch();

function login_fail(string $ip, string $username): void
{
    rate_limit_register("login_ip:$ip", 900);
    rate_limit_register("login_user:" . strtolower($username), 900);
}

if (!$user) {
    login_fail($ip, $username);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
    $database->close();
    exit;
}

if (!$user['is_active']) {
    login_fail($ip, $username);
    http_response_code(403);
    echo json_encode(['error' => 'Account not activated. Check your email.']);
    $database->close();
    exit;
}

$banned = $user['banned_until'] ?? null;
if ($banned !== null) {
    if ($banned === 'indefinite' || strtotime($banned) > time()) {
        http_response_code(403);
        $until = $banned === 'indefinite' ? 'indefinitely' : "until $banned";
        echo json_encode(['error' => "You have been banned $until"]);
        $database->close();
        exit;
    }
}

if (!password_verify($password, $user['password'])) {
    login_fail($ip, $username);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
    $database->close();
    exit;
}

$token = bin2hex(random_bytes(32));
$stmt = $database->prepare("INSERT INTO tokens (token, username, expires_at) VALUES (?, ?, datetime('now', '+7 days'))");
    $stmt->execute([$token, $user['username']]);

// Update active status
$activeFile = __DIR__ . '/active_users.json';
$active = file_exists($activeFile) ? (json_decode(file_get_contents($activeFile), true) ?? []) : [];
if (!in_array($user['username'], $active)) {
    $active[] = $user['username'];
    file_put_contents($activeFile, json_encode($active));
}

$database->close();

// Check for offline messages
$offlineFile = __DIR__ . '/offline.json';
$offlineNotifications = [];
if (file_exists($offlineFile)) {
    $offline = json_decode(file_get_contents($offlineFile), true) ?? [];
    if (isset($offline[$user['username']])) {
        $offlineNotifications = $offline[$user['username']];
    }
}

echo json_encode([
    'token' => $token,
    'username' => $user['username'],
    'role' => $user['role'] ?? 'user',
    'offline_messages' => $offlineNotifications,
]);
