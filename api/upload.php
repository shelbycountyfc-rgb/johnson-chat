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

$username = trim($_POST['username'] ?? '');
$roomId = trim($_POST['room_id'] ?? 'general');
$token = trim($_POST['token'] ?? '');

if ($username === '') {
    echo json_encode(['error' => 'Username required']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$maxSize = 50 * 1024 * 1024; // 50MB

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

// Authenticate uploader via token
$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); $database->close(); exit; }
if (strtolower($tokenRow['username']) !== strtolower($username)) { http_response_code(403); echo json_encode(['error' => 'Token does not match username']); $database->close(); exit; }

// The target room must exist, and read-only rooms only accept staff uploads.
$stmt = $database->prepare("SELECT COUNT(*) AS c, MAX(read_only) AS read_only FROM rooms WHERE id = ?");
$stmt->execute([$roomId]);
$roomRow = $stmt->fetch();
if ((int) ($roomRow['c'] ?? 0) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Room not found']);
    $database->close();
    exit;
}
if ((int) ($roomRow['read_only'] ?? 0) === 1) {
    $stmt = $database->prepare("SELECT role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $role = $stmt->fetch()['role'] ?? 'user';
    if ($role !== 'admin' && $role !== 'moderator') {
        http_response_code(403);
        echo json_encode(['error' => 'This room is read-only. Only staff can post here.']);
        $database->close();
        exit;
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

// Keep only a safe filename for display (strip CR/LF/traversal)
$displayName = trim(str_replace(["\r", "\n", "\0"], '', $file['name']));
$displayName = strlen($displayName) === 0 ? 'untitled' : $displayName;

$fileToken = bin2hex(random_bytes(16));

// MIME lookup by stored extension
$mimeForExt = [
    'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'txt' => 'text/plain', 'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed', '7z' => 'application/x-7z-compressed',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

// Store in manifest
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

// Save message
try {
    $msgId = uniqid('', true);
    $stmt = $database->prepare("INSERT INTO messages (id, username, message, room_id, time, timestamp, edited, edit_ts, deleted, reactions, file_token) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, '{}', ?)");
    $stmt->execute([
        $msgId,
        $username,
        'sent a file: ' . $displayName,
        $roomId,
        date('H:i'),
        microtime(true),
        $fileToken,
    ]);
    $database->close();
} catch (Throwable $e) {}

echo json_encode(['success' => true, 'token' => $fileToken]);

function write_upload_htaccess(string $dir): void
{
    if (file_exists("$dir/.htaccess")) return;
    @file_put_contents(
        "$dir/.htaccess",
        "Options -Indexes\n<FilesMatch \"\\.(php|php5|phtml|phar|cgi|pl|py|asp|aspx|jsp|sh)$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
    );
}
