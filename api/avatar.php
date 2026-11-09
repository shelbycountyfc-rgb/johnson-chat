<?php
error_reporting(0);
$username = trim($_GET['username'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $username)) {
    $username = '';
}
if ($username === '') {
    header('Content-Type: image/svg+xml');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="avatar.svg"');
    readfile(__DIR__ . '/../default-avatar.svg');
    exit;
}

$avatarDir = __DIR__ . '/../storage/avatars/';
$exts = ['jpg', 'png', 'gif', 'webp'];
foreach ($exts as $ext) {
    $path = $avatarDir . $username . '.' . $ext;
    if (file_exists($path)) {
        $mime = [
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ][$ext] ?? 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="avatar' . $ext . '"');
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }
}

// Default avatar
header('Content-Type: image/svg+xml');
header('X-Content-Type-Options: nosniff');
readfile(__DIR__ . '/../default-avatar.svg');
