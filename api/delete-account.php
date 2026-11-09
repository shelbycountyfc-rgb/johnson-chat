<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$password = $input['password'] ?? '';

if ($token === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token and password required']);
    exit;
}

// Rate limit account deletion per IP (destructive action).
$ip = rate_limit_client_ip();
if (!rate_limit_is_allowed("delacc_ip:$ip", 5, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Please try again later.']);
    exit;
}
rate_limit_register("delacc_ip:$ip", 3600);

$database = Database::getInstance();

$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
$username = $tokenRow['username'];

// Re-verify password before any destruction.
$stmt = $database->prepare("SELECT password FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();
if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Incorrect password']);
    $database->close();
    exit;
}

// --- Delete everything tied to this account ---
try {
    // SQLite: user, tokens, messages, blocks, read_state
    $database->prepare("DELETE FROM tokens WHERE username = ?")->execute([$username]);
    $database->prepare("DELETE FROM messages WHERE username = ?")->execute([$username]);
    $database->prepare("DELETE FROM blocks WHERE username = ? OR blocked = ?")->execute([$username, $username]);
    $database->prepare("DELETE FROM read_state WHERE username = ?")->execute([$username]);
    $database->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
    $database->prepare("DELETE FROM rooms WHERE created_by = ?")->execute([$username]);
} catch (Throwable $e) {}

// Uploaded files
$uploadsDir = __DIR__ . '/../uploads';
$manifestFile = "$uploadsDir/manifest.json";
if (file_exists($manifestFile)) {
    $manifest = json_decode(file_get_contents($manifestFile), true) ?? [];
    if (is_array($manifest)) {
        foreach ($manifest as $ftok => $entry) {
            if (is_array($entry) && strtolower((string) ($entry['uploaded_by'] ?? '')) === strtolower($username)) {
                $fp = $uploadsDir . '/' . basename((string) ($entry['path'] ?? ''));
                if ($fp !== "$uploadsDir/" && is_file($fp)) {
                    @unlink($fp);
                }
                unset($manifest[$ftok]);
            }
        }
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));
    }
}

// Private messages (remove this user's messages from every room file)
$msgDir = __DIR__ . '/../private_messages';
if (is_dir($msgDir)) {
    foreach (glob("$msgDir/*.json") as $f) {
        $msgs = json_decode(file_get_contents($f), true) ?? [];
        if (!is_array($msgs)) continue;
        $before = count($msgs);
        $msgs = array_values(array_filter($msgs, fn($m) => strtolower((string) ($m['username'] ?? '')) !== strtolower($username)));
        if (count($msgs) < $before) {
            if (empty($msgs)) {
                @unlink($f);
            } else {
                file_put_contents($f, json_encode($msgs));
            }
        }
    }
}

// Friends lists, private room membership, offline store
$friendsFile = __DIR__ . '/friends.json';
if (file_exists($friendsFile)) {
    $friends = json_decode(file_get_contents($friendsFile), true) ?? [];
    if (is_array($friends)) {
        unset($friends[$username]);
        foreach ($friends as $u => $list) {
            if (is_array($list)) {
                $list = array_values(array_filter($list, fn($f) => strtolower((string) $f) !== strtolower($username)));
            }
            $friends[$u] = $list;
        }
        file_put_contents($friendsFile, json_encode($friends, JSON_PRETTY_PRINT));
    }
}

$privateFile = __DIR__ . '/private.json';
if (file_exists($privateFile)) {
    $private = json_decode(file_get_contents($privateFile), true) ?? [];
    if (is_array($private)) {
        if (isset($private['rooms'])) {
            foreach ($private['rooms'] as $rid => $room) {
                $users = $room['users'] ?? [];
                if (is_array($users)) {
                    $users = array_values(array_filter($users, fn($u) => strtolower((string) $u) !== strtolower($username)));
                    if (empty($users)) {
                        unset($private['rooms'][$rid]);
                    } else {
                        $private['rooms'][$rid]['users'] = $users;
                    }
                }
            }
        }
        if (isset($private['pending'])) {
            $private['pending'] = array_values(array_filter($private['pending'], fn($p) => strtolower((string) ($p['from'] ?? '')) !== strtolower($username) && strtolower((string) ($p['to'] ?? '')) !== strtolower($username)));
        }
        if (isset($private['file_notify'])) {
            $private['file_notify'] = array_values(array_filter($private['file_notify'], fn($n) => strtolower((string) ($n['from'] ?? '')) !== strtolower($username)));
        }
        file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));
    }
}

$offlineFile = __DIR__ . '/offline.json';
if (file_exists($offlineFile)) {
    $offline = json_decode(file_get_contents($offlineFile), true) ?? [];
    if (is_array($offline)) {
        unset($offline[$username]);
        foreach ($offline as $target => &$list) {
            if (!is_array($list)) continue;
            $list = array_values(array_filter($list, fn($m) => strtolower((string) ($m['from'] ?? '')) !== strtolower($username)));
            if (empty($list)) unset($offline[$target]);
        }
        unset($list);
        file_put_contents($offlineFile, json_encode($offline, JSON_PRETTY_PRINT));
    }
}

// Remove from active users + avatar
$activeFile = __DIR__ . '/active_users.json';
if (file_exists($activeFile)) {
    $active = json_decode(file_get_contents($activeFile), true) ?? [];
    if (is_array($active)) {
        $active = array_values(array_filter($active, fn($u) => $u !== $username));
        file_put_contents($activeFile, json_encode($active));
    }
}
foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
    $avatar = __DIR__ . "/../storage/avatars/$username.$ext";
    if (is_file($avatar)) {
        @unlink($avatar);
    }
}

$database->close();
echo json_encode(['success' => true, 'message' => 'Account deleted']);