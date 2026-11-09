<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/mailer.php';
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

// Rate limit account creation per IP.
$ip = rate_limit_client_ip();
if (!rate_limit_is_allowed("signup_ip:$ip", 10, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many signups. Please try again later.']);
    exit;
}
rate_limit_register("signup_ip:$ip", 3600);
$fullName = trim($input['fullName'] ?? '');
$address = trim($input['address'] ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($email === '' || $username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email, username, and password are required']);
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

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

$database = Database::getInstance();

// Check if username already exists
$stmt = $database->prepare("SELECT COUNT(*) as count FROM users WHERE LOWER(username) = LOWER(?)");
$stmt->execute([$username]);
if ($stmt->fetch()['count'] > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Username already taken']);
    $database->close();
    exit;
}

// Check if email already exists
$stmt = $database->prepare("SELECT COUNT(*) as count FROM users WHERE LOWER(email) = LOWER(?)");
$stmt->execute([$email]);
if ($stmt->fetch()['count'] > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Email already registered']);
    $database->close();
    exit;
}

$verificationRequired = app_mail_require_verification();
$activationToken = $verificationRequired ? bin2hex(random_bytes(24)) : '';
$isActive = $verificationRequired ? 0 : 1;

$stmt = $database->prepare("INSERT INTO users (full_name, address, phone, email, username, password, ip, activation_token, is_active, role, muted_until, banned_until, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $fullName,  // maps to full_name column
    $address,
    $phone,
    $email,
    $username,
    password_hash($password, PASSWORD_DEFAULT),
    $ip,
    $activationToken,
    $isActive,
    'user',
    null,
    null,
    date('Y-m-d H:i:s')
]);

$database->close();

$response = [
    'success' => true,
    'message' => $verificationRequired
        ? 'Account created. Check your email to activate it.'
        : 'Account created. You can now log in.',
    'email' => $email,
    'activation_required' => $verificationRequired,
];
if ($verificationRequired) {
    $link = app_base_url() . '/index.php?action=activate&token=' . urlencode($activationToken) . '&username=' . urlencode($username);
    app_mail_send(
        $email,
        'Activate your JohnsonChat account',
        "Hello $username,\n\nUse this link to activate your account:\n\n$link\n\nIf you did not create this account, ignore this email.\n"
    );
    if (app_mail_debug_links()) {
        $response['activation_link'] = $link;
    }
}

echo json_encode($response);
