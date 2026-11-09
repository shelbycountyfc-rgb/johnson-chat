<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$target = trim($input['target'] ?? '');

if ($token === '' || $target === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token and target required']);
    exit;
}

// Verify token
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

// Throttle request spam per user.
if (!rate_limit_is_allowed("privreq_user:" . strtolower($username), 30, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please try again later.']);
    $database->close();
    exit;
}
rate_limit_register("privreq_user:" . strtolower($username), 3600);

if ($target === $username) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot chat with yourself']);
    $database->close();
    exit;
}

// Blocking: either direction blocks the request.
if (private_pair_blocked($database, $username, $target)) {
    http_response_code(403);
    echo json_encode(['error' => 'Messaging unavailable']);
    $database->close();
    exit;
}

// Build room key
$myLower = strtolower($username);
$targetLower = strtolower($target);
$roomKey = [min($myLower, $targetLower), max($myLower, $targetLower)];
$roomId = $roomKey[0] . '_' . $roomKey[1];

// Check if room already exists
$privateFile = __DIR__ . '/private.json';
$private = file_exists($privateFile) ? (json_decode(file_get_contents($privateFile), true) ?? []) : [];

if (isset($private['rooms'][$roomId])) {
    echo json_encode(['success' => true, 'already_exists' => true, 'room_id' => $roomId]);
    exit;
}

// Check for existing pending request
foreach (($private['pending'] ?? []) as $p) {
    if ($p['from'] === $username && $p['to'] === $target) {
        echo json_encode(['success' => true, 'message' => 'Request already sent']);
        exit;
    }
}

// Create pending request
$private['pending'][] = [
    'id' => uniqid('', true),
    'from' => $username,
    'to' => $target,
    'created_at' => date('Y-m-d H:i:s'),
];
file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));

echo json_encode(['success' => true, 'message' => 'Request sent']);
