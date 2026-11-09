<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Check both SQLite and file-based users
$adminExists = false;

// Check SQLite
try {
    $dbFile = __DIR__ . '/data/chat.db';
    if (file_exists($dbFile) && filesize($dbFile) > 0) {
        $pdo = new PDO("sqlite:$dbFile");
        $stmt = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin' AND is_active = 1");
        $adminExists = ($stmt->fetch()['c'] ?? 0) > 0;
    }
} catch (Throwable $e) {}

// Fallback to users.json
if (!$adminExists) {
    $usersFile = __DIR__ . '/users.json';
    if (file_exists($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true) ?? [];
        foreach ($users as $u) {
            if (isset($u['role']) && $u['role'] === 'admin') {
                $adminExists = true;
                break;
            }
        }
    }
}

echo json_encode(['admin_exists' => $adminExists]);
