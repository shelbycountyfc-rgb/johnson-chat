<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$database = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $username = $_GET['username'] ?? '';
    $viewer = $_GET['viewer'] ?? '';

    if ($username === '') { echo json_encode(['success' => false, 'error' => 'Username required']); $database->close(); exit; }

    $stmt = $database->prepare("SELECT username, full_name, role, created_at, profile_pic, age, state, city, description FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) { echo json_encode(['success' => false, 'error' => 'User not found']); $database->close(); exit; }

    $user['avatar_url'] = 'api/avatar.php?username=' . urlencode($username);
    $user['is_self'] = ($viewer === $username);
    // Normalize legacy rows that were double-escaped at signup time.
    foreach (['full_name', 'description', 'city', 'state'] as $f) {
        if (isset($user[$f])) {
            $user[$f] = html_entity_decode((string) $user[$f], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    $database->close();
    echo json_encode(['success' => true, 'user' => $user]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_origin();
    $input = $_POST;
    if (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $input = array_merge($input, $raw);
    }

    $token = trim($input['token'] ?? '');

    if ($token === '') { http_response_code(400); echo json_encode(['error' => 'Token required']); $database->close(); exit; }

$tokenData = app_session_check($database, $token);

    if (!$tokenData) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); $database->close(); exit; }
    $username = $tokenData['username'];

    $fields = [];
    foreach (['full_name', 'age', 'state', 'city', 'description'] as $f) {
        if (array_key_exists($f, $input)) {
            $fields[$f] = trim((string) $input[$f]);
        }
    }

    if (!empty($fields)) {
        if (isset($fields['age']) && $fields['age'] !== '' && (!ctype_digit($fields['age']) || (int) $fields['age'] < 1 || (int) $fields['age'] > 120)) {
            http_response_code(400);
            echo json_encode(['error' => 'Age must be a number between 1 and 120']);
            $database->close();
            exit;
        }
        if (isset($fields['description']) && mb_strlen($fields['description']) > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'Description must be under 500 characters']);
            $database->close();
            exit;
        }

        $set = implode(', ', array_map(fn($f) => "$f = ?", array_keys($fields)));
        $vals = array_values($fields);
        $vals[] = $username;

        $stmt = $database->prepare("UPDATE users SET $set WHERE username = ?");
        $stmt->execute($vals);

        $database->close();
        echo json_encode(['success' => true, 'message' => 'Profile updated']);
        exit;
    }

    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No avatar file uploaded']);
        $database->close();
        exit;
    }

    $file = $_FILES['avatar'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only JPG, PNG, GIF, WEBP allowed']);
        $database->close();
        exit;
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Avatar must be under 2MB']);
        $database->close();
        exit;
    }

    // Verify it is a real image (rejects HTML/SVG/script polyglots)
    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) {
        http_response_code(400);
        echo json_encode(['error' => 'File is not a valid image']);
        $database->close();
        exit;
    }

    $avatarDir = __DIR__ . '/../storage/avatars/';
    if (!is_dir($avatarDir)) {
        mkdir($avatarDir, 0755, true);
    }

    // Remove old avatars
    foreach (['jpg', 'png', 'gif', 'webp'] as $oldExt) {
        $oldPath = $avatarDir . $username . '.' . $oldExt;
        if (file_exists($oldPath)) { unlink($oldPath); }
    }

    $saveExt = ($ext === 'jpeg') ? 'jpg' : $ext;
    $destPath = $avatarDir . $username . '.' . $saveExt;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save avatar']);
        $database->close();
        exit;
    }

    // Update database
    $stmt = $database->prepare("UPDATE users SET profile_pic = ? WHERE username = ?");
    $stmt->execute([$saveExt, $username]);

    $database->close();
    echo json_encode(['success' => true, 'avatar_url' => 'api/avatar.php?username=' . urlencode($username)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
$database->close();
