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
$target = trim($input['target'] ?? '');
$action = trim($input['action'] ?? '');
$duration = $input['duration'] ?? '';

if ($token === '' || $target === '' || $action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token, target, and action required']);
    exit;
}

// Verify token and get username
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$requester = $tokenRow['username'];

// Get requester role
$stmt = $database->prepare("SELECT role FROM users WHERE username = ?");
$stmt->execute([$requester]);
$reqUser = $stmt->fetch();
$reqRole = $reqUser['role'] ?? 'user';

$isAdmin = $reqRole === 'admin';
$isMod = $reqRole === 'moderator';

if (!$isAdmin && !$isMod) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized']);
    $database->close();
    exit;
}

// Some actions require admin
if (in_array($action, ['ban', 'lift_ban', 'promote', 'demote']) && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    $database->close();
    exit;
}

// Admins cannot punish or demote other admins (prevents lockout/admin-steering)
$stmt = $database->prepare("SELECT role, username FROM users WHERE username = ?");
$stmt->execute([$target]);
$targetUser = $stmt->fetch();
if ($targetUser && $targetUser['role'] === 'admin' && $requester !== $targetUser['username']) {
    http_response_code(403);
    echo json_encode(['error' => 'Cannot modify another admin']);
    $database->close();
    exit;
}

// Admins cannot demote or punish themselves
if ($isAdmin && $requester === $target && in_array($action, ['demote', 'promote', 'ban', 'lift_ban', 'mute'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admins cannot change their own admin role']);
    $database->close();
    exit;
}

// Calculate duration
$expires = null;
$durationLabel = '';
switch ($duration) {
    case '1h': $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); $durationLabel = '1 hour'; break;
    case '24h': $expires = date('Y-m-d H:i:s', strtotime('+24 hours')); $durationLabel = '24 hours'; break;
    case '1d': $expires = date('Y-m-d H:i:s', strtotime('+1 day')); $durationLabel = '1 day'; break;
    case '7d': $expires = date('Y-m-d H:i:s', strtotime('+7 days')); $durationLabel = '7 days'; break;
    case 'indefinite': $expires = 'indefinite'; $durationLabel = 'indefinite'; break;
}

switch ($action) {
    case 'mute':
        if (!$expires) { echo json_encode(['error' => 'Invalid duration']); break; }
        $stmt = $database->prepare("UPDATE users SET muted_until = ? WHERE username = ?");
        $stmt->execute([$expires, $target]);
        echo json_encode(['success' => true, 'message' => "$target muted for $durationLabel"]);
        break;

    case 'unmute':
        $stmt = $database->prepare("UPDATE users SET muted_until = NULL WHERE username = ?");
        $stmt->execute([$target]);
        echo json_encode(['success' => true, 'message' => "$target unmuted"]);
        break;

    case 'ban':
        if (!$expires) { echo json_encode(['error' => 'Invalid duration']); break; }
        $stmt = $database->prepare("UPDATE users SET banned_until = ? WHERE username = ?");
        $stmt->execute([$expires, $target]);
        echo json_encode(['success' => true, 'message' => "$target banned for $durationLabel"]);
        break;

    case 'lift_ban':
        $stmt = $database->prepare("UPDATE users SET banned_until = NULL WHERE username = ?");
        $stmt->execute([$target]);
        echo json_encode(['success' => true, 'message' => "$target's ban lifted"]);
        break;

    case 'promote':
        $stmt = $database->prepare("UPDATE users SET role = 'moderator' WHERE username = ?");
        $stmt->execute([$target]);
        echo json_encode(['success' => true, 'message' => "$target promoted to moderator"]);
        break;

    case 'demote':
        $stmt = $database->prepare("UPDATE users SET role = 'user' WHERE username = ?");
        $stmt->execute([$target]);
        echo json_encode(['success' => true, 'message' => "$target demoted to user"]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}

$database->close();
