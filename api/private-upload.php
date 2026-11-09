<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/chatlib.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$username = trim($_POST['username'] ?? '');
$roomId = trim($_POST['room_id'] ?? '');
$token = trim($_POST['token'] ?? '');

if ($username === '' || $roomId === '') {
    echo json_encode(['error' => 'Username and room_id required']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$maxSize = 50 * 1024 * 1024;

// MIME -> allowed extension map. Only these file types are accepted.
$allowedMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'application/zip' => 'zip',
    'application/x-rar-compressed' => 'rar',
    'application/x-7z-compressed' => '7z',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];
$mimeForExt = [
    'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'txt' => 'text/plain', 'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed', '7z' => 'application/x-7z-compressed',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$clientMime = strtolower(trim($file['type'] ?? ''));
$extForMime = $allowedMap[$clientMime] ?? null;
if ($extForMime === null) {
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

// Verify the bytes actually match the claimed type (rejects spoofed MIME
// polyglots / HTML disguised as an allowed type).
$magicMime = null;
if (function_exists('finfo_open')) {
    $magicMime = (string) @(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($magicMime !== '') {
        $magicMime = strtolower(trim($magicMime));
    }
}
if ($magicMime !== '') {
    $realExt = $allowedMap[$magicMime] ?? null;
    // The bytes must be a type we accept AND agree with the claimed type.
    if ($realExt === null || $realExt !== $extForMime) {
        echo json_encode(['error' => 'File content does not match its type']);
        exit;
    }
}

if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'File too large (max 50MB)']);
    exit;
}

// Authenticate uploader via token and verify room membership
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); $database->close(); exit; }
if (strtolower($tokenRow['username']) !== strtolower($username)) { http_response_code(403); echo json_encode(['error' => 'Token does not match username']); $database->close(); exit; }
if (!is_private_room_member($roomId, $username)) { http_response_code(403); echo json_encode(['error' => 'Not a room member']); $database->close(); exit; }

// Blocking: refuse to relay files if either party blocked the other.
$bParts = explode('_', $roomId);
foreach ($bParts as $p) {
    if (strtolower($p) !== strtolower($username)) {
        if (private_pair_blocked($database, $username, $p)) {
            http_response_code(403);
            echo json_encode(['error' => 'Messaging unavailable']);
            $database->close();
            exit;
        }
        break;
    }
}

$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0777, true);
write_upload_htaccess($uploadsDir);

$safeName = bin2hex(random_bytes(16)) . '.' . $extForMime;
$destPath = "$uploadsDir/$safeName";

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

$displayName = trim(str_replace(["\r", "\n", "\0"], '', $file['name']));
$displayName = strlen($displayName) === 0 ? 'untitled' : $displayName;

$fileToken = bin2hex(random_bytes(16));

$manifestFile = "$uploadsDir/manifest.json";
$manifest = file_exists($manifestFile) ? (json_decode(file_get_contents($manifestFile), true) ?? []) : [];
$manifest[$fileToken] = [
    'name' => $displayName,
    'size' => $file['size'],
    'type' => $mimeForExt[$extForMime] ?? 'application/octet-stream',
    'path' => basename($destPath),
    'uploaded_by' => $username,
    'uploaded_at' => date('Y-m-d H:i:s'),
];
file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

// Save message to private chat
$msgDir = __DIR__ . '/../private_messages';
if (!is_dir($msgDir)) mkdir($msgDir, 0777, true);

$msgFile = "$msgDir/" . basename($roomId) . ".json";
$msgs = file_exists($msgFile) ? (json_decode(file_get_contents($msgFile), true) ?? []) : [];

$msgs[] = [
    'id' => uniqid('', true),
    'username' => $username,
    'message' => 'sent a file: ' . $displayName,
    'time' => date('H:i'),
    'timestamp' => microtime(true),
    'file_token' => $fileToken,
    'edited' => 0,
    'edit_ts' => 0,
    'deleted' => 0,
    'reactions' => [],
    'read' => false,
];

file_put_contents($msgFile, json_encode($msgs));

// Notify the other user
$privateFile = __DIR__ . '/private.json';
$private = file_exists($privateFile) ? (json_decode(file_get_contents($privateFile), true) ?? []) : [];

// Determine the other user from room_id
$parts = explode('_', $roomId);
$otherUser = '';
foreach ($parts as $p) {
    if (strtolower($p) !== strtolower($username)) {
        $otherUser = $p;
        break;
    }
}

if ($otherUser !== '') {
    if (!isset($private['file_notify'])) $private['file_notify'] = [];
    $private['file_notify'][] = [
        'room_id' => $roomId,
        'from' => $username,
        'to' => $otherUser,
        'file' => $displayName,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));
}

echo json_encode(['success' => true, 'token' => $fileToken]);

function write_upload_htaccess(string $dir): void
{
    if (file_exists("$dir/.htaccess")) return;
    @file_put_contents(
        "$dir/.htaccess",
        "Options -Indexes\n<FilesMatch \"\\.(php|php5|phtml|phar|cgi|pl|py|asp|aspx|jsp|sh)$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
    );
}
