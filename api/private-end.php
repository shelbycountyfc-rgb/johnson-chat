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
$roomId = strtolower(trim($input['room_id'] ?? ''));

if ($token === '' || $roomId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token and room_id required']);
    exit;
}

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

// The room key is the sorted pair of usernames, so membership can be checked
// even before the room exists (pending request cancellation).
$parts = explode('_', $roomId);
$isPair = count($parts) === 2
    && (strtolower($username) === $parts[0] || strtolower($username) === $parts[1]);

if (!$isPair) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a room member']);
    $database->close();
    exit;
}

// Cancel any pending request between this pair. Only the pair themselves may
// do this; an outsider must never be able to clear someone else's invitation.
$a = $parts[0];
$b = $parts[1];
foreach (($private['pending'] ?? []) as $i => $p) {
    $fi = strtolower((string) ($p['from'] ?? ''));
    $ti = strtolower((string) ($p['to'] ?? ''));
    $key = [min($fi, $ti), max($fi, $ti)];
    if ($key[0] === $a && $key[1] === $b) {
        unset($private['pending'][$i]);
    }
}
if (isset($private['pending'])) {
    $private['pending'] = array_values($private['pending']);
}

if (isset($private['rooms'][$roomId])) {
    $isMember = false;
    foreach (($private['rooms'][$roomId]['users'] ?? []) as $u) {
        if (strtolower((string) $u) === strtolower($username)) { $isMember = true; break; }
    }
    if (!$isMember) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a room member']);
        $database->close();
        exit;
    }

    // Remove the room so a brand-new request can be sent next time.
    unset($private['rooms'][$roomId]);

    // Delete the private message history.
    $msgFile = __DIR__ . '/../private_messages/' . basename($roomId) . '.json';
    if (file_exists($msgFile)) { @unlink($msgFile); }

    // Delete the D&D game state for this room.
    $dndFile = __DIR__ . '/data/dnd/' . md5($roomId) . '.json';
    if (file_exists($dndFile)) { @unlink($dndFile); }

    // Delete any saved game boards for this room.
    foreach (glob(__DIR__ . '/data/games/' . md5($roomId) . '_*.json') ?: [] as $gameFile) {
        @unlink($gameFile);
    }
}

file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));

echo json_encode(['success' => true]);
$database->close();
