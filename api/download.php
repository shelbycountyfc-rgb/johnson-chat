<?php
error_reporting(0);
header('Access-Control-Allow-Origin: *');

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    exit;
}

$uploadsDir = __DIR__ . '/../uploads';
$manifestFile = "$uploadsDir/manifest.json";

if (!file_exists($manifestFile)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

$manifest = json_decode(file_get_contents($manifestFile), true) ?? [];
$entry = $manifest[$token] ?? null;

if (!$entry) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Only ever serve files stored inside the uploads dir (basename guards the manifest path)
$filePath = $uploadsDir . '/' . basename((string) ($entry['path'] ?? ''));
if ($filePath === $uploadsDir . '/' || !is_file($filePath)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

$name = (string) ($entry['name'] ?? 'download');
$mime = (string) ($entry['type'] ?? 'application/octet-stream');
$size = (int) ($entry['size'] ?? filesize($filePath));

// Only serve recognized safe MIME types; otherwise force a generic binary type
$safeMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'application/zip',
    'application/x-rar-compressed', 'application/x-7z-compressed',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!in_array(strtolower($mime), $safeMimes, true)) {
    $mime = 'application/octet-stream';
}

// Sanitize the download filename for the header (strip CR/LF and quotes)
$name = str_replace(["\r", "\n", "\0"], '', $name);
$name = str_replace(['"'], "'", $name);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=86400');
readfile($filePath);