<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$username = trim($_GET['username'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($username === '') {
    echo json_encode(['requests' => [], 'file_notify' => []]);
    exit;
}

// Require a token before revealing any notification data
if ($token === '') {
    http_response_code(403);
    echo json_encode(['error' => 'Token required']);
    exit;
}

// Authenticate caller; only allow viewing your own notifications
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow || strtolower($tokenRow['username']) !== strtolower($username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$database->close();

$privateFile = __DIR__ . '/private.json';
$private = file_exists($privateFile) ? (json_decode(file_get_contents($privateFile), true) ?? []) : [];

// Find pending requests for this user
$requests = [];
foreach ($private['pending'] ?? [] as $p) {
    if (strtolower((string) $p['to']) === strtolower($username)) {
        $requests[] = $p;
    }
}

// Find pending requests sent BY this user (lets the requester see when
// their request leaves the queue, i.e. the recipient responded)
$outgoing = [];
foreach ($private['pending'] ?? [] as $p) {
    if (strtolower((string) $p['from']) === strtolower($username)) {
        $outgoing[] = $p;
    }
}

// One-shot notifications of declined requests sent TO this user. Returned
// once, then removed so the "declined" state is only surfaced a single time.
$deniedFrom = [];
if (isset($private['denied']) && is_array($private['denied'])) {
    $kept = [];
    foreach ($private['denied'] as $d) {
        $keep = true;
        if (strtolower((string) ($d['to'] ?? '')) === strtolower($username)) {
            $deniedFrom[] = $d['from'] ?? '';
            $keep = false;
        }
        if ($keep) $kept[] = $d;
    }
    $private['denied'] = $kept;
    file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));
}

// Find file notifications for this user
$fileNotify = [];
foreach ($private['file_notify'] ?? [] as $n) {
    $roomUsers = explode('_', $n['room_id']);
    $other = $roomUsers[0] === strtolower($username) ? $roomUsers[1] : $roomUsers[0];
    if (strtolower($n['to'] ?? '') === strtolower($username) || $other !== '') {
        // Check if this notify targets this user or is in a room with this user
        if (isset($n['to']) && strtolower($n['to']) === strtolower($username)) {
            $fileNotify[] = $n;
        } elseif (!isset($n['to'])) {
            // Check if user is part of this room
            $parts = explode('_', $n['room_id']);
            if (in_array(strtolower($username), array_map('strtolower', $parts))) {
                $fileNotify[] = $n;
            }
        }
    }
}

// Find recent video call rings directed at this user
$calls = [];
$ringsFile = __DIR__ . '/rings.json';
if (file_exists($ringsFile)) {
    $ringsRaw = json_decode(file_get_contents($ringsFile), true) ?? [];
    // Prune rings older than the 45s ringing window so a disconnect never leaves stale popups behind.
    $ringsLive = array_values(array_filter($ringsRaw ?? [], function ($r) {
        return (int) ($r['created'] ?? 0) >= time() - 45;
    }));
    if (count($ringsLive) !== count($ringsRaw)) {
        file_put_contents($ringsFile, json_encode($ringsLive, JSON_PRETTY_PRINT));
    }
    // Only rings refreshed within the last 15s are considered active calls; the
    // caller re-sends its ring every 3s, so live calls are always inside the window.
    $rings = array_values(array_filter($ringsLive, function ($r) use ($username) {
        return strtolower((string) ($r['to'] ?? '')) === strtolower($username)
            && (int) ($r['created'] ?? 0) >= time() - 15;
    }));
    foreach (array_slice($rings, -2) as $r) {
        $calls[] = [
            'id' => $r['id'] ?? '',
            'room_id' => $r['room_id'] ?? '',
            'from' => $r['from'] ?? '',
            'created_at' => $r['created'] ?? 0,
            'call_id' => $r['call_id'] ?? '',
        ];
    }
}

echo json_encode(['requests' => $requests, 'outgoing' => $outgoing, 'denied_from' => $deniedFrom, 'file_notify' => $fileNotify, 'calls' => $calls]);
