<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Get active users from active_users.json (updated by login/logout/heartbeat)
$activeFile = __DIR__ . '/active_users.json';
$active = file_exists($activeFile) ? (json_decode(file_get_contents($activeFile), true) ?? []) : [];

// Verify users still exist in database
try {
    $database = Database::getInstance();
    $valid = [];
    foreach ($active as $u) {
        $stmt = $database->prepare("SELECT username FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$u]);
        if ($stmt->fetch()) $valid[] = $u;
    }
    $database->close();

    // Clean up invalid entries
    if (count($valid) !== count($active)) {
        file_put_contents($activeFile, json_encode($valid));
    }
    $active = $valid;
} catch (Throwable $e) {}

echo json_encode(['users' => $active]);
