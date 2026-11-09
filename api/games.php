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
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$isGet = ($_SERVER['REQUEST_METHOD'] === 'GET');
$token  = trim($isGet ? ($_GET['token'] ?? '')  : ($input['token'] ?? ''));
$roomId = trim($isGet ? ($_GET['room_id'] ?? '') : ($input['room_id'] ?? ''));
$gameType = trim($isGet ? ($_GET['game'] ?? '')  : ($input['game'] ?? ''));

if ($token === '' || $roomId === '' || $gameType === '') {
    http_response_code(400);
    echo json_encode(['error' => 'token, room_id, and game required']);
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

// Game state lives inside a private room, so only its two members may read or
// write it. Without this a guessed pair id exposes the whole board.
if (!is_private_room_member($roomId, $username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a room member']);
    $database->close();
    exit;
}

$GAME_TYPES = ['tictactoe','rps','hangman','connect4','guessthenumber','memory','higherlower','wordscramble','diceduel'];
if (!in_array($gameType, $GAME_TYPES)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid game type']);
    $database->close();
    exit;
}

$dataDir = __DIR__ . '/data/games';
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
$path = $dataDir . '/' . md5($roomId) . '_' . $gameType . '.json';

function loadState(string $path): ?array {
    if (!file_exists($path)) return null;
    $raw = @file_get_contents($path);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// GET: return current state for this room+game
if ($isGet) {
    $state = loadState($path);
    echo json_encode(['ok' => true, 'state' => $state]);
    $database->close();
    exit;
}

// POST
$action = trim($input['action'] ?? '');

switch ($action) {
    case 'save':
        $newState = $input['state'] ?? null;
        if (!is_array($newState)) {
            http_response_code(400);
            echo json_encode(['error' => 'state must be an object']);
            $database->close();
            exit;
        }
        file_put_contents($path, json_encode($newState));
        echo json_encode(['ok' => true, 'state' => $newState]);
        break;

    case 'delete':
        if (file_exists($path)) @unlink($path);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use save or delete.']);
}

$database->close();
