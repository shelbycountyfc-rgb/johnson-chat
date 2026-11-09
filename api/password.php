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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// Rate limit password actions per IP.
if (!rate_limit_is_allowed("pw_ip:$ip", 10, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Please try again later.']);
    exit;
}
rate_limit_register("pw_ip:$ip", 3600);

$database = Database::getInstance();

if ($action === 'request') {
    // Send a password reset link for the given username or email.
    $id = trim($input['id'] ?? ''); // username OR email
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Username or email required']);
        $database->close();
        exit;
    }
    $stmt = $database->prepare("SELECT username, email FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)");
    $stmt->execute([$id, $id]);
    $user = $stmt->fetch();
    if (!$user) {
        // Do not reveal whether the account exists.
        echo json_encode(['success' => true, 'message' => 'If that account exists, a reset link has been generated.']);
        $database->close();
        exit;
    }
    $resetToken = bin2hex(random_bytes(24)) . '.' . time();
    $stmt = $database->prepare("UPDATE users SET activation_token = ? WHERE username = ?");
    $stmt->execute([$resetToken, $user['username']]);

    $link = app_base_url() . '/index.php?action=reset&token=' . urlencode($resetToken) . '&username=' . urlencode($user['username']);
    app_mail_send(
        (string) ($user['email'] ?? ''),
        'Reset your JohnsonChat password',
        "Hello {$user['username']},\n\nA password reset was requested for your account.\n\nReset link: $link\n\nThis link expires in one hour. If you did not request it, ignore this email.\n"
    );
    $database->close();
    $response = [
        'success' => true,
        'message' => app_mail_transport() === 'log'
            ? 'If that account exists, a reset link has been generated.'
            : 'If that account exists, a reset link has been emailed.',
    ];
    if (app_mail_debug_links()) {
        $response['reset_link'] = $link;
    }
    echo json_encode($response);
    exit;
}

if ($action === 'reset') {
    $token = trim($input['token'] ?? '');
    $password = $input['password'] ?? '';
    if ($token === '' || mb_strlen($password) < 4) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or incomplete reset request']);
        $database->close();
        exit;
    }
    $stmt = $database->prepare("SELECT username, activation_token, created_at FROM users WHERE activation_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid reset token']);
        $database->close();
        exit;
    }
    // Reset tokens carry their issue time; links expire after one hour.
    if (str_contains($token, '.')) {
        $parts = explode('.', $token, 2);
        if (!ctype_digit($parts[1]) || time() - (int) $parts[1] > 3600) {
            http_response_code(400);
            echo json_encode(['error' => 'This reset link has expired. Request a new one.']);
            $database->close();
            exit;
        }
    }
    $stmt = $database->prepare("UPDATE users SET password = ?, activation_token = '' WHERE username = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['username']]);
    // Invalidate existing sessions so an attacker can't keep an old token alive.
    $stmt = $database->prepare("DELETE FROM tokens WHERE username = ?");
    $stmt->execute([$user['username']]);
    $database->close();
    echo json_encode(['success' => true, 'message' => 'Password updated. You can now log in.']);
    exit;
}

if ($action === 'resend_activation') {
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Username or email required']);
        $database->close();
        exit;
    }
    $stmt = $database->prepare("SELECT username, email, is_active FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)");
    $stmt->execute([$id, $id]);
    $user = $stmt->fetch();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No account found with that username/email.']);
        $database->close();
        exit;
    }
    if ((int) ($user['is_active'] ?? 0) === 1) {
        echo json_encode(['success' => false, 'message' => 'Account is already active. Just log in.']);
        $database->close();
        exit;
    }
    $activateToken = bin2hex(random_bytes(24));
    $stmt = $database->prepare("UPDATE users SET activation_token = ? WHERE username = ?");
    $stmt->execute([$activateToken, $user['username']]);
    $link = app_base_url() . '/index.php?action=activate&token=' . urlencode($activateToken) . '&username=' . urlencode($user['username']);
    app_mail_send(
        (string) ($user['email'] ?? ''),
        'Activate your JohnsonChat account',
        "Hello {$user['username']},\n\nUse this link to activate your account:\n\n$link\n\nIf you did not create this account, ignore this email.\n"
    );
    $database->close();
    $response = [
        'success' => true,
        'message' => app_mail_transport() === 'log'
            ? 'Activation link generated.'
            : 'Activation link emailed.',
    ];
    if (app_mail_debug_links()) {
        $response['activation_link'] = $link;
    }
    echo json_encode($response);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);