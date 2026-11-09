<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
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
$requestId = trim($input['request_id'] ?? '');
$roomIdGiven = trim($input['room_id'] ?? '');
$accept = !empty($input['accept']);

if ($token === '' || ($requestId === '' && $roomIdGiven === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Token and request_id (or room_id) required']);
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

$privateFile = __DIR__ . '/private.json';
$private = file_exists($privateFile) ? (json_decode(file_get_contents($privateFile), true) ?? []) : [];

$found = false;
$fromUser = '';
$myLower = strtolower($username);
foreach (($private['pending'] ?? []) as $i => $p) {
    $matches = false;
    if ($requestId !== '') {
        $matches = $p['id'] === $requestId && strtolower((string) $p['to']) === $myLower;
    } else {
        $fromLower = strtolower((string) $p['from']);
        $roomKey = [min($myLower, $fromLower), max($myLower, $fromLower)];
        $matches = ($roomKey[0] . '_' . $roomKey[1]) === strtolower($roomIdGiven)
            && strtolower((string) $p['to']) === $myLower;
    }
    if ($matches) {
        $fromUser = $p['from'];
        $found = true;
        array_splice($private['pending'], $i, 1);
        break;
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

if ($accept) {
    // Create the room
    $myLower = strtolower($username);
    $fromLower = strtolower($fromUser);
    $roomKey = [min($myLower, $fromLower), max($myLower, $fromLower)];
    $roomId = $roomKey[0] . '_' . $roomKey[1];

    if (!isset($private['rooms'][$roomId])) {
        $private['rooms'][$roomId] = [
            'users' => [$fromUser, $username],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true, 'room_id' => $roomId]);
} else {
    // Tell the requester their request was declined so they can close the waiting view.
    if (!isset($private['denied'])) $private['denied'] = [];
    $private['denied'][] = [
        'to' => $fromUser,
        'from' => $username,
        'at' => time(),
    ];
    file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'declined' => true]);
}
