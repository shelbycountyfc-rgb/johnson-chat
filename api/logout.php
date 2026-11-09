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
$token = $input['token'] ?? '';

if ($token !== '') {
    try {
        $database = Database::getInstance();

        // Get username before deleting token
$row = app_session_check($database, $token);

        $stmt = $database->prepare("DELETE FROM tokens WHERE token = ?");
        $stmt->execute([$token]);

        // Remove from active users
        if ($row) {
            $activeFile = __DIR__ . '/active_users.json';
            $active = file_exists($activeFile) ? (json_decode(file_get_contents($activeFile), true) ?? []) : [];
            $active = array_values(array_filter($active, fn($u) => $u !== $row['username']));
            file_put_contents($activeFile, json_encode($active));

            // Clear this user's room messages so nobody sees them after logout
            $stmt = $database->prepare("DELETE FROM messages WHERE username = ?");
            $stmt->execute([$row['username']]);

            // Clear this user's read cursors
            $stmt = $database->prepare("DELETE FROM read_state WHERE username = ?");
            $stmt->execute([$row['username']]);

            // Clear this user's private messages from file stores
            $msgDir = __DIR__ . '/../private_messages';
            if (is_dir($msgDir)) {
                foreach (glob("$msgDir/*.json") as $f) {
                    $msgs = json_decode(file_get_contents($f), true) ?? [];
                    if (!is_array($msgs)) continue;
                    $before = count($msgs);
                    $msgs = array_values(array_filter($msgs, fn($m) => ($m['username'] ?? '') !== $row['username']));
                    if (count($msgs) < $before) {
                        if (empty($msgs)) {
                            @unlink($f);
                        } else {
                            file_put_contents($f, json_encode($msgs));
                        }
                    }
                }
            }

            // Clear this user's offline messages store
            $offlineFile = __DIR__ . '/offline.json';
            if (file_exists($offlineFile)) {
                $offline = json_decode(file_get_contents($offlineFile), true) ?? [];
                if (is_array($offline)) {
                    unset($offline[$row['username']]);
                    foreach ($offline as $target => &$list) {
                        if (!is_array($list)) continue;
                        $list = array_values(array_filter($list, fn($m) => ($m['from'] ?? '') !== $row['username']));
                        if (empty($list)) unset($offline[$target]);
                    }
                    unset($list);
                    file_put_contents($offlineFile, json_encode($offline, JSON_PRETTY_PRINT));
                }
            }

            // Uploads are session-scoped like messages: delete files this user
            // uploaded so nothing persists on the server after they log out.
            $uploadsDir = __DIR__ . '/../uploads';
            $manifestFile = "$uploadsDir/manifest.json";
            if (file_exists($manifestFile)) {
                $manifest = json_decode(file_get_contents($manifestFile), true) ?? [];
                if (is_array($manifest)) {
                    $changed = false;
                    foreach ($manifest as $ftok => $entry) {
                        if (!is_array($entry)) continue;
                        if (strtolower((string) ($entry['uploaded_by'] ?? '')) !== strtolower($row['username'])) continue;
                        $filePath = $uploadsDir . '/' . basename((string) ($entry['path'] ?? ''));
                        if ($filePath !== $uploadsDir . '/' && is_file($filePath)) {
                            @unlink($filePath);
                        }
                        unset($manifest[$ftok]);
                        $changed = true;
                    }
                    if ($changed) {
                        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));
                    }
                }
            }
        }

        $database->close();
    } catch (Throwable $e) {}
}

echo json_encode(['success' => true]);
