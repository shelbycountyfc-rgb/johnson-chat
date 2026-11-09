<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$callsDir = __DIR__ . '/calls';
$ringsFile = __DIR__ . '/rings.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = trim($_GET['room_id'] ?? '');
    $username = trim($_GET['username'] ?? '');
    $since = (float) ($_GET['since'] ?? 0);
    $token = trim($_GET['token'] ?? '');

    if ($roomId === '' || $username === '' || $token === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Token required']);
        exit;
    }

    // Signaling contains live SDP/ICE data, so the reader must prove they are
    // the claimed user and a member of this private room.
    $database = Database::getInstance();
    $tokenRow = app_session_check($database, $token);
    if (!$tokenRow || strtolower((string) $tokenRow['username']) !== strtolower($username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        $database->close();
        exit;
    }
    if (!is_private_room_member($roomId, $username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not allowed']);
        $database->close();
        exit;
    }
    $database->close();

    $file = $callsDir . '/' . basename($roomId) . '.json';
    if (!file_exists($file)) {
        echo json_encode(['events' => [], 'server_time' => microtime(true)]);
        exit;
    }

    $entries = json_decode(file_get_contents($file), true) ?? [];
    $pruned = array_values(array_filter($entries, function ($e) {
        return (float) ($e['timestamp'] ?? 0) >= microtime(true) - 300;
    }));
    if (count($pruned) !== count($entries)) {
        file_put_contents($file, json_encode($pruned));
        $entries = $pruned;
    }
    $events = [];
    $maxTs = $since;
    foreach ($entries as $e) {
        if (strtolower((string) ($e['from'] ?? '')) === strtolower($username)) { continue; }
        $ts = (float) ($e['timestamp'] ?? 0);
        if ($ts > $since) {
            $events[] = $e;
        }
        if ($ts > $maxTs) $maxTs = $ts;
    }

    if ($maxTs <= 0) $maxTs = microtime(true);
    echo json_encode(['events' => $events, 'server_time' => $maxTs]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$type = trim($input['type'] ?? '');
$roomId = trim($input['room_id'] ?? '');
$from = trim($input['from'] ?? '');
$to = trim($input['to'] ?? '');
$payload = (string) ($input['payload'] ?? '');
$callId = trim($input['call_id'] ?? '');

if ($token === '' || $type === '' || $roomId === '' || $from === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

// Verify token maps to the sender
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$tokenUser = (string) $tokenRow['username'];
if (strtolower($tokenUser) !== strtolower($from)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token mismatch']);
    $database->close();
    exit;
}

if (!is_dir($callsDir)) { @mkdir($callsDir, 0777, true); }

// Recipient acted on an incoming ring (accepted or declined): consume the
// ring so the popup does not reappear on subsequent polls.
if ($type === 'ring_clear') {
    $clearId = trim($input['ring_id'] ?? '');
    if (file_exists($ringsFile)) {
        $rings = json_decode(file_get_contents($ringsFile), true) ?? [];
        $rings = array_values(array_filter($rings, function ($r) use ($clearId, $roomId, $from) {
            if ($clearId !== '') {
                return ($r['id'] ?? '') !== $clearId;
            }
            // No id: clear every ring for this room directed at the requester.
            return !(strtolower((string) ($r['room_id'] ?? '')) === strtolower($roomId)
                && strtolower((string) ($r['to'] ?? '')) === strtolower($from));
        }));
        file_put_contents($ringsFile, json_encode($rings, JSON_PRETTY_PRINT));
    }
    echo json_encode(['success' => true]);
    exit;
}

$stamp = microtime(true);

// Ring notifications go into a global list so private-check.php can surface them
if ($type === 'ring') {
    $rings = file_exists($ringsFile) ? (json_decode(file_get_contents($ringsFile), true) ?? []) : [];
    $now = time();
    $rings = array_values(array_filter($rings ?? [], function ($r) use ($now) {
        return (int) ($r['created'] ?? 0) >= $now - 45;
    }));
    $rings[] = [
        'id' => uniqid('', true),
        'room_id' => $roomId,
        'from' => $from,
        'to' => $to,
        'created' => $now,
        'call_id' => $callId,
    ];
    file_put_contents($ringsFile, json_encode($rings, JSON_PRETTY_PRINT));
}

$file = $callsDir . '/' . basename($roomId) . '.json';
$entries = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

// Prune old entries to keep the file small (keep ~5 minutes)
$cutoff = microtime(true) - 300;
$entries = array_values(array_filter($entries, function ($e) use ($cutoff) {
    return (float) ($e['timestamp'] ?? 0) >= $cutoff;
}));

// Hanging up drops the entire call: remove every other event for this call_id
// (ring/offer/answer/candidate) so stale signaling can never be re-served, but
// keep the hangup itself so the peer still gets it. Also purge its ring record
// so private-check.php stops surfacing an incoming-call popup.
if ($type === 'hangup') {
    $callIdClean = (string) $callId;
    $entries = array_values(array_filter($entries, function ($e) use ($callIdClean) {
        return (($e['type'] ?? '') === 'hangup') || ((string) ($e['call_id'] ?? '') !== $callIdClean);
    }));
    if (file_exists($ringsFile)) {
        $rings = json_decode(file_get_contents($ringsFile), true) ?? [];
        $rings = array_values(array_filter($rings, function ($r) use ($callIdClean) {
            return (string) ($r['call_id'] ?? '') !== $callIdClean;
        }));
        file_put_contents($ringsFile, json_encode($rings, JSON_PRETTY_PRINT));
    }
}

$lastTs = 0.0;
if (count($entries) > 0) { $lastTs = (float) $entries[count($entries) - 1]['timestamp']; }
if ($stamp <= $lastTs) { $stamp = $lastTs + 0.000001; } // keep strictly increasing

$entries[] = [
    'id' => uniqid('', true),
    'type' => $type,
    'room_id' => $roomId,
    'from' => $from,
    'to' => $to,
    'payload' => $payload,
    'call_id' => $callId,
    'timestamp' => $stamp,
];
file_put_contents($file, json_encode($entries));

echo json_encode(['success' => true]);