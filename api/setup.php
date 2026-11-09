<?php
error_reporting(0);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// One-time installer: lock it once an active administrator exists.
$setupDb = __DIR__ . '/data/chat.db';
if (is_file($setupDb) && filesize($setupDb) > 0) {
    try {
        $setupPdo = new PDO('sqlite:' . $setupDb);
        $adminCount = (int) $setupPdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($adminCount > 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
    } catch (Throwable $e) {
        // Let the installer handle a missing or incomplete schema.
    }
}

// --- Helpers ---
function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function write_file(string $path, string $content): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

// --- Step 1: Requirements ---
function check_requirements(): array
{
    $checks = [];
    $root = dirname(__DIR__);
    $api = __DIR__;

    // PHP version
    $checks[] = [
        'name' => 'PHP 8.0+',
        'pass' => PHP_VERSION_ID >= 80000,
        'note' => PHP_VERSION,
    ];

    // Extensions
    $exts = ['pdo_sqlite', 'json', 'mbstring', 'session', 'openssl'];
    foreach ($exts as $ext) {
        $checks[] = [
            'name' => $ext,
            'pass' => extension_loaded($ext),
            'note' => extension_loaded($ext) ? 'loaded' : 'missing',
        ];
    }

    // --- Required PHP API files ---
    $phpFiles = [
        'database.php', 'login.php', 'logout.php', 'verify.php',
        'signup.php', 'send.php', 'poll.php', 'rooms.php',
        'upload.php', 'download.php', 'avatar.php', 'profile.php',
        'ip.php', 'friends.php', 'user-room.php', 'user-status.php',
        'active-users.php', 'admin-action.php', 'check-admin.php',
        'moderation.php', 'private-check.php', 'private-poll.php',
        'private-request.php', 'private-respond.php', 'private-send.php',
        'private-end.php', 'private-upload.php', 'send-offline.php', 'clear-offline.php',
        'offline-check.php', 'error_handler.php', 'security.php', 'ratelimit.php',
        'setup.php', 'setup-admin.php',
        'schema.php', 'chatlib.php', 'react.php', 'message-action.php',
        'typing.php', 'read-state.php', 'search.php', 'blocks.php',
        'password.php', 'delete-account.php',
    ];
    $missing = [];
    foreach ($phpFiles as $f) {
        if (!file_exists("$api/$f")) $missing[] = $f;
    }
    $checks[] = [
        'name' => 'PHP API files',
        'pass' => empty($missing),
        'note' => empty($missing) ? count($phpFiles) . '/' . count($phpFiles) : 'Missing: ' . implode(', ', $missing),
    ];

    // --- Root frontend files ---
    $rootFiles = ['messnger.html', 'script.js', 'style.css', 'default-avatar.svg'];
    $missing = [];
    foreach ($rootFiles as $f) {
        if (!file_exists("$root/$f")) $missing[] = $f;
    }
    $checks[] = [
        'name' => 'Frontend files',
        'pass' => empty($missing),
        'note' => empty($missing) ? count($rootFiles) . '/' . count($rootFiles) : 'Missing: ' . implode(', ', $missing),
    ];

    // --- Setup wizard files ---
    $setupFiles = ['index.html', 'script.js', 'style.css'];
    $missing = [];
    foreach ($setupFiles as $f) {
        if (!file_exists("$root/setup/$f")) $missing[] = $f;
    }
    $checks[] = [
        'name' => 'Setup wizard files',
        'pass' => empty($missing),
        'note' => empty($missing) ? count($setupFiles) . '/' . count($setupFiles) : 'Missing: ' . implode(', ', $missing),
    ];

    // --- JSON data stores (legacy migration source; optional) ---
    $jsonFiles = [
        'users.json', 'tokens.json', 'rooms.json', 'friends.json',
        'private.json', 'user_rooms.json', 'active_users.json', 'offline.json',
    ];
    $missing = [];
    foreach ($jsonFiles as $f) {
        if (!file_exists("$api/$f")) $missing[] = $f;
    }
    $checks[] = [
        'name' => 'JSON data stores (optional)',
        'pass' => true,
        'note' => empty($missing) ? 'all present' : 'not required for fresh install: ' . implode(', ', $missing),
    ];

    // --- Writable directories ---
    $dirs = [
        "$api/data" => 'api/data/',
        "$api/messages" => 'api/messages/',
        "$root/storage" => 'storage/',
        "$root/storage/avatars" => 'storage/avatars/',
        "$root/storage/files" => 'storage/files/',
        "$root/private_messages" => 'private_messages/',
    ];
    foreach ($dirs as $dir => $label) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $checks[] = [
            'name' => "Writable $label",
            'pass' => is_writable($dir),
            'note' => is_writable($dir) ? 'ok' : 'not writable',
        ];
    }

    $allPass = !in_array(false, array_column($checks, 'pass'), true);
    return ['all_pass' => $allPass, 'checks' => $checks];
}

// --- Step 2: Initialize Database ---
function upgrade_user_columns(PDO $pdo): void
{
    $userCols = [];
    foreach ($pdo->query('PRAGMA table_info(users)') as $col) {
        $userCols[$col['name']] = true;
    }
    $upgrades = [
        'age' => "ALTER TABLE users ADD COLUMN age TEXT DEFAULT ''",
        'state' => "ALTER TABLE users ADD COLUMN state TEXT DEFAULT ''",
        'city' => "ALTER TABLE users ADD COLUMN city TEXT DEFAULT ''",
        'description' => "ALTER TABLE users ADD COLUMN description TEXT DEFAULT ''",
    ];
    foreach ($upgrades as $colName => $sql) {
        if (!isset($userCols[$colName])) {
            $pdo->exec($sql);
        }
    }
}

function init_database(): array
{
    $dbFile = __DIR__ . '/data/chat.db';

    // If database already has tables, just run upgrades and skip
    if (file_exists($dbFile) && filesize($dbFile) > 0) {
        try {
            $pdo = new PDO("sqlite:$dbFile");
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('users', $tables) && in_array('rooms', $tables) && in_array('messages', $tables)) {
                try {
                    upgrade_user_columns($pdo);
                } catch (Throwable $e) {
                    // ignored
                }
                return ['success' => true, 'message' => 'Database already initialized', 'tables' => $tables];
            }
        } catch (Throwable $e) {
            // Continue to recreate
        }
    }

    try {
        $pdo = new PDO("sqlite:$dbFile", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

        // Create/upgrade user table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT DEFAULT '',
            address TEXT DEFAULT '',
            phone TEXT DEFAULT '',
            email TEXT DEFAULT '',
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            ip TEXT DEFAULT '',
            activation_token TEXT DEFAULT '',
            is_active INTEGER DEFAULT 0,
            role TEXT DEFAULT 'user',
            muted_until TEXT DEFAULT NULL,
            banned_until TEXT DEFAULT NULL,
            profile_pic TEXT DEFAULT '',
            age TEXT DEFAULT '',
            state TEXT DEFAULT '',
            city TEXT DEFAULT '',
            description TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now'))
        )");

        // Upgrade existing installs: add profile columns if missing
        upgrade_user_columns($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
            token TEXT PRIMARY KEY,
            username TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            created_by TEXT DEFAULT 'system',
            created_at TEXT DEFAULT (datetime('now')),
            last_activity TEXT DEFAULT (datetime('now'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id TEXT PRIMARY KEY,
            username TEXT NOT NULL,
            message TEXT NOT NULL,
            room_id TEXT NOT NULL,
            time TEXT DEFAULT '',
            timestamp REAL DEFAULT 0,
            FOREIGN KEY (room_id) REFERENCES rooms(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            room_id TEXT NOT NULL,
            joined_at TEXT DEFAULT (datetime('now')),
            UNIQUE(username, room_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mod_flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id TEXT NOT NULL,
            flagger_username TEXT DEFAULT '',
            category TEXT DEFAULT '',
            reason TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now'))
        )");

        // Get created tables
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        return ['success' => true, 'message' => 'Database initialized', 'tables' => $tables];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// --- Step 3: Create default rooms ---
function create_default_rooms(): array
{
    $dbFile = __DIR__ . '/data/chat.db';
    if (!file_exists($dbFile)) {
        return ['success' => false, 'message' => 'Database not found'];
    }

    try {
        $pdo = new PDO("sqlite:$dbFile");
        $existing = $pdo->query("SELECT id FROM rooms")->fetchAll(PDO::FETCH_COLUMN);

        $defaults = [
            ['id' => 'general', 'name' => 'General'],
        ];

        $created = [];
        foreach ($defaults as $room) {
            if (!in_array($room['id'], $existing)) {
                $stmt = $pdo->prepare("INSERT INTO rooms (id, name, created_by, created_at, last_activity) VALUES (?, ?, 'system', datetime('now'), datetime('now'))");
                $stmt->execute([$room['id'], $room['name']]);
                $created[] = $room['id'];
            }
        }

        $allAfter = $pdo->query("SELECT id FROM rooms")->fetchAll(PDO::FETCH_COLUMN);

        return ['success' => true, 'message' => 'Rooms ready', 'rooms' => $allAfter, 'created' => $created];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Room error: ' . $e->getMessage()];
    }
}

// --- Step 4: Create admin account ---
function create_admin(string $username, string $password): array
{
    $dbFile = __DIR__ . '/data/chat.db';
    if (!file_exists($dbFile)) {
        return ['success' => false, 'message' => 'Database not found'];
    }

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        return ['success' => false, 'message' => 'Username must be 3-20 characters'];
    }
    if (mb_strlen($password) < 4) {
        return ['success' => false, 'message' => 'Password must be at least 4 characters'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['success' => false, 'message' => 'Username can only contain letters, numbers, and underscores'];
    }

    try {
        $pdo = new PDO("sqlite:$dbFile");

        // Check if admin already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE role = 'admin' AND is_active = 1");
        $stmt->execute();
        if ($stmt->fetch()['c'] > 0) {
            return ['success' => false, 'message' => 'Admin already exists'];
        }

        // Check if username taken
        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$username]);
        if ($stmt->fetch()['c'] > 0) {
            return ['success' => false, 'message' => 'Username already taken'];
        }

        $stmt = $pdo->prepare("INSERT INTO users (full_name, username, password, is_active, role, created_at) VALUES ('Admin', ?, ?, 1, 'admin', datetime('now'))");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

        return ['success' => true, 'message' => 'Admin account created'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Admin error: ' . $e->getMessage()];
    }
}

// --- Migrate file data to SQLite ---
function migrate_file_users(): array
{
    $usersFile = __DIR__ . '/users.json';
    if (!file_exists($usersFile)) {
        return ['success' => true, 'count' => 0];
    }
    try {
        $users = json_decode(file_get_contents($usersFile), true) ?? [];
        if (empty($users)) return ['success' => true, 'count' => 0];

        $pdo = new PDO("sqlite:" . __DIR__ . '/data/chat.db');
        $count = 0;
        foreach ($users as $u) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE LOWER(username) = LOWER(?)");
            $stmt->execute([$u['username'] ?? '']);
            if ($stmt->fetch()['c'] > 0) continue;

            $stmt = $pdo->prepare("INSERT INTO users (full_name, address, phone, email, username, password, ip, activation_token, is_active, role, muted_until, banned_until, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $u['fullName'] ?? '',
                $u['address'] ?? '',
                $u['phone'] ?? '',
                $u['email'] ?? '',
                $u['username'] ?? '',
                $u['password'] ?? '',
                $u['ip'] ?? '',
                $u['activation_token'] ?? '',
                $u['is_active'] ?? false,
                $u['role'] ?? 'user',
                $u['muted_until'] ?? null,
                $u['banned_until'] ?? null,
                $u['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $count++;
        }
        return ['success' => true, 'count' => $count];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function migrate_file_tokens(): array
{
    $tokensFile = __DIR__ . '/tokens.json';
    if (!file_exists($tokensFile)) {
        return ['success' => true, 'count' => 0];
    }
    try {
        $tokens = json_decode(file_get_contents($tokensFile), true) ?? [];
        if (empty($tokens)) return ['success' => true, 'count' => 0];

        $pdo = new PDO("sqlite:" . __DIR__ . '/data/chat.db');
        $count = 0;
        foreach ($tokens as $token => $data) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM tokens WHERE token = ?");
            $stmt->execute([$token]);
            if ($stmt->fetch()['c'] > 0) continue;

            $stmt = $pdo->prepare("INSERT INTO tokens (token, username, created_at, expires_at) VALUES (?, ?, ?, datetime(?, '+7 days'))");
            $stmt->execute([$token, $data['username'] ?? '', $data['created_at'] ?? date('Y-m-d H:i:s'), $data['created_at'] ?? date('Y-m-d H:i:s')]);
            $count++;
        }
        return ['success' => true, 'count' => $count];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function migrate_file_rooms(): array
{
    $roomsFile = __DIR__ . '/rooms.json';
    if (!file_exists($roomsFile)) {
        return ['success' => true, 'count' => 0];
    }
    try {
        $rooms = json_decode(file_get_contents($roomsFile), true) ?? [];
        if (empty($rooms)) return ['success' => true, 'count' => 0];

        $pdo = new PDO("sqlite:" . __DIR__ . '/data/chat.db');
        $count = 0;
        foreach ($rooms as $r) {
            $id = $r['id'] ?? '';
            if ($id === '') continue;
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM rooms WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetch()['c'] > 0) continue;

            $stmt = $pdo->prepare("INSERT INTO rooms (id, name, created_by, created_at, last_activity) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $r['name'] ?? $id, $r['created_by'] ?? 'system', $r['created_at'] ?? date('Y-m-d H:i:s'), $r['last_activity'] ?? date('Y-m-d H:i:s')]);
            $count++;
        }
        return ['success' => true, 'count' => $count];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// --- Main handler ---
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Status check
    $dbExists = file_exists(__DIR__ . '/data/chat.db') && filesize(__DIR__ . '/data/chat.db') > 0;
    $adminExists = false;
    if ($dbExists) {
        try {
            $pdo = new PDO("sqlite:" . __DIR__ . '/data/chat.db');
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin' AND is_active = 1");
            $adminExists = ($stmt->fetch()['c'] ?? 0) > 0;
        } catch (Throwable $e) {}
    }
    echo json_encode([
        'db_exists' => $dbExists,
        'admin_exists' => $adminExists,
        'php_version' => PHP_VERSION,
    ]);
    exit;
}

if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

require_once __DIR__ . '/security.php';
require_valid_origin();

$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');

switch ($action) {
    case 'requirements':
        echo json_encode(check_requirements());
        break;

    case 'init_database':
        $result = init_database();
        if ($result['success']) {
            migrate_file_users();
            migrate_file_tokens();
            migrate_file_rooms();
        }
        echo json_encode($result);
        break;

    case 'create_rooms':
        echo json_encode(create_default_rooms());
        break;

    case 'create_admin':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        echo json_encode(create_admin($username, $password));
        break;

    case 'quick_setup':
        // One-call full setup with optional admin creation
        $steps = [];

        $req = check_requirements();
        $steps['requirements'] = $req;
        if (!$req['all_pass']) {
            echo json_encode(['success' => false, 'step' => 'requirements', 'message' => 'Requirements not met', 'checks' => $req['checks']]);
            break;
        }

        $db = init_database();
        $steps['database'] = $db;
        if (!$db['success']) {
            echo json_encode(['success' => false, 'step' => 'database', 'message' => $db['message']]);
            break;
        }

        migrate_file_users();
        migrate_file_tokens();
        migrate_file_rooms();

        $rooms = create_default_rooms();
        $steps['rooms'] = $rooms;

        $adminResult = ['created' => false, 'message' => 'Skipped'];
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        if ($username !== '' && $password !== '') {
            $adminResult = create_admin($username, $password);
            $steps['admin'] = $adminResult;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Setup complete',
            'steps' => $steps,
        ]);
        break;

    default:
        json_error('Unknown action');
}
