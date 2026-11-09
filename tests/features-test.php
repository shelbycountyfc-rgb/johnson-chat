<?php
/**
 * Feature integration test for replies, pins, deep links, PWA, push crypto,
 * and the email log transport.
 *
 * Run against a live Apache/PHP server:
 *   APP_URL=http://127.0.0.1:8080 php tests/features-test.php
 */
declare(strict_types=1);

$BASE = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8080', '/');
$API = $BASE . '/api';
$ROOT = dirname(__DIR__);
$DBFILE = $ROOT . '/api/data/chat.db';
$pass = 0;
$fail = 0;
$failures = [];
$createdUsers = [];
$createdRooms = [];
$createdPrivateRooms = [];
$newMailFiles = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
        return;
    }
    $fail++;
    $failures[] = $name . ($detail !== '' ? "  [$detail]" : '');
    echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
}

function note(string $text): void
{
    echo "\n== $text ==\n";
}

function db(): ?PDO
{
    global $DBFILE;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_file($DBFILE)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $DBFILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
    } catch (Throwable $e) {
        return null;
    }
    return $pdo;
}

/** @return array{status:int,json:mixed,raw:string,headers:array} */
function http(string $method, string $url, $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
}

function api(string $method, string $path, $body = null, ?string $token = null): array
{
    global $API;
    $url = str_starts_with($path, 'http') ? $path : $API . '/' . ltrim($path, '/');
    if ($token !== null) {
        if ($method === 'GET') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
        } elseif (is_array($body)) {
            $body['token'] = $token;
        }
    }
    return http($method, $url, $body);
}

function login(string $username, string $password): ?string
{
    $r = api('POST', 'login.php', ['username' => $username, 'password' => $password]);
    return is_array($r['json']) && !empty($r['json']['token']) ? (string) $r['json']['token'] : null;
}

function makeUser(string $username, string $password): void
{
    global $createdUsers;
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('No database available');
    }
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, username, password, is_active, role, created_at)
        VALUES (?, ?, ?, ?, 1, 'user', datetime('now'))");
    $stmt->execute(['Feature ' . $username, $username . '@example.test', $username, password_hash($password, PASSWORD_DEFAULT)]);
    $createdUsers[] = $username;
}

function roomIdFor(string $a, string $b): string
{
    $parts = [strtolower($a), strtolower($b)];
    sort($parts);
    return $parts[0] . '_' . $parts[1];
}

function pollMessages(string $room, string $token, float $since = 0): array
{
    $r = api('GET', 'poll.php?room_id=' . rawurlencode($room) . '&since=' . $since, null, $token);
    return is_array($r['json']) ? $r['json'] : [];
}

function b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function b64uDecode(string $value): string
{
    $value = strtr($value, '-_', '+/');
    $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
    return (string) base64_decode($value, true);
}

function rawSignatureToDer(string $raw): string
{
    if (strlen($raw) !== 64) {
        return '';
    }
    $r = ltrim(substr($raw, 0, 32), "\x00");
    $s = ltrim(substr($raw, 32), "\x00");
    if ($r === '') { $r = "\x00"; }
    if ($s === '') { $s = "\x00"; }
    if (ord($r[0]) & 0x80) { $r = "\x00" . $r; }
    if (ord($s[0]) & 0x80) { $s = "\x00" . $s; }
    $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
    return "\x30" . chr(strlen($seq)) . $seq;
}

echo "JohnsonChat feature test against $BASE\n";
$pdo = db();
if (!$pdo) {
    fwrite(STDERR, "No api/data/chat.db; run setup first.\n");
    exit(2);
}
try { $pdo->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}

$adminToken = login('admin', 'admin123');
if (!$adminToken) {
    api('POST', 'setup.php', ['action' => 'quick_setup', 'username' => 'admin', 'password' => 'admin123']);
    $adminToken = login('admin', 'admin123');
}
check('admin test account is available', $adminToken !== null);

$tag = substr(bin2hex(random_bytes(4)), 0, 7);
$u1 = 'f' . $tag . 'a';
$u2 = 'f' . $tag . 'b';
$u3 = 'f' . $tag . 'c';
$pw = 'Passw0rd!' . $tag;
makeUser($u1, $pw);
makeUser($u2, $pw);
makeUser($u3, $pw);
$t1 = login($u1, $pw);
$t2 = login($u2, $pw);
$t3 = login($u3, $pw);
check('feature test users can sign in', $t1 && $t2 && $t3);

/* ------------------------------------------------------------------ */
note('Replies in public rooms');
/* ------------------------------------------------------------------ */

$roomName = 'Feature Room ' . $tag;
$rc = api('POST', 'rooms.php', ['name' => $roomName], $t1);
$room = (string) ($rc['json']['room']['id'] ?? '');
if ($room !== '') { $createdRooms[] = $room; }
check('creator can create a test room', $rc['status'] === 200 && $room !== '', $rc['raw']);

$parent = api('POST', 'send.php', ['username' => $u1, 'message' => 'parent ' . $tag, 'room_id' => $room], $t1);
$parentId = (string) ($parent['json']['message']['id'] ?? '');
check('parent message is sent', $parent['status'] === 200 && $parentId !== '', $parent['raw']);

$reply = api('POST', 'send.php', [
    'username' => $u2,
    'message' => 'reply ' . $tag,
    'room_id' => $room,
    'reply_to' => $parentId,
], $t2);
check('reply message is accepted', $reply['status'] === 200, $reply['raw']);
$replyId = (string) ($reply['json']['message']['id'] ?? '');

$poll = pollMessages($room, $t2);
$replyRow = null;
foreach (($poll['messages'] ?? []) as $m) {
    if ((string) ($m['id'] ?? '') === $replyId) { $replyRow = $m; }
}
check('poll carries reply metadata', is_array($replyRow) && ($replyRow['reply']['id'] ?? '') === $parentId, json_encode($replyRow));
check('reply preview contains the parent author and text', is_array($replyRow) && ($replyRow['reply']['username'] ?? '') === $u1 && str_contains((string) ($replyRow['reply']['message'] ?? ''), 'parent ' . $tag), json_encode($replyRow['reply'] ?? null));

$crossRoom = api('POST', 'rooms.php', ['name' => 'Other ' . $tag], $t1);
$otherRoom = (string) ($crossRoom['json']['room']['id'] ?? '');
if ($otherRoom !== '') { $createdRooms[] = $otherRoom; }
$badReply = api('POST', 'send.php', [
    'username' => $u2,
    'message' => 'bad reply',
    'room_id' => $otherRoom,
    'reply_to' => $parentId,
], $t2);
check('reply target from another room is rejected', $badReply['status'] === 400, 'status ' . $badReply['status'] . ' ' . $badReply['raw']);

$missingReply = api('POST', 'send.php', [
    'username' => $u2,
    'message' => 'bad reply',
    'room_id' => $room,
    'reply_to' => 'missing-' . $tag,
], $t2);
check('missing reply target is rejected', $missingReply['status'] === 400, 'status ' . $missingReply['status']);

/* ------------------------------------------------------------------ */
note('Pinned messages');
/* ------------------------------------------------------------------ */

$badPin = api('POST', 'pins.php', ['action' => 'pin', 'room_id' => $room, 'message_id' => $parentId], $t2);
check('a regular non-creator cannot pin', $badPin['status'] === 403, 'status ' . $badPin['status'] . ' ' . $badPin['raw']);

$pin = api('POST', 'pins.php', ['action' => 'pin', 'room_id' => $room, 'message_id' => $parentId], $t1);
check('the room creator can pin', !empty($pin['json']['success']), $pin['raw']);
$pins = $pin['json']['pins'] ?? [];
check('pin list contains the pinned message', count($pins) === 1 && ($pins[0]['message_id'] ?? '') === $parentId, $pin['raw']);
$pollPins = pollMessages($room, $t2);
check('poll returns room pins', count($pollPins['pins'] ?? []) === 1 && ($pollPins['pins'][0]['message_id'] ?? '') === $parentId, json_encode($pollPins['pins'] ?? []));

$unpin = api('POST', 'pins.php', ['action' => 'unpin', 'room_id' => $room, 'message_id' => $parentId], $t1);
check('creator can unpin', !empty($unpin['json']['success']) && count($unpin['json']['pins'] ?? []) === 0, $unpin['raw']);

$pinAgain = api('POST', 'pins.php', ['action' => 'pin', 'room_id' => $room, 'message_id' => $parentId], $t1);
check('message can be pinned again', !empty($pinAgain['json']['success']), $pinAgain['raw']);
$deletePinned = api('POST', 'message-action.php', [
    'action' => 'delete',
    'message_id' => $parentId,
    'kind' => 'public',
    'room_id' => $room,
], $t1);
check('pinned author can delete the message', !empty($deletePinned['json']['success']), $deletePinned['raw']);
$afterDeletePin = api('POST', 'pins.php', ['action' => 'list', 'room_id' => $room], $t1);
check('deleting a message removes its pin', count($afterDeletePin['json']['pins'] ?? []) === 0, $afterDeletePin['raw']);

$adminPinMessage = api('POST', 'send.php', ['username' => $u1, 'message' => 'admin pin target ' . $tag, 'room_id' => $room], $t1);
$adminPinId = (string) ($adminPinMessage['json']['message']['id'] ?? '');
$adminPin = api('POST', 'pins.php', ['action' => 'pin', 'room_id' => $room, 'message_id' => $adminPinId], $adminToken);
check('an admin can pin in a non-owned room', !empty($adminPin['json']['success']), $adminPin['raw']);

/* ------------------------------------------------------------------ */
note('Private replies');
/* ------------------------------------------------------------------ */

$offline = api('POST', 'send-offline.php', ['target' => $u2, 'message' => 'private seed ' . $tag], $t1);
$privRoom = (string) ($offline['json']['room_id'] ?? '');
if ($privRoom !== '') { $createdPrivateRooms[] = $privRoom; }
check('private test room is available', $offline['status'] === 200 && $privRoom !== '', $offline['raw']);

$privParent = api('POST', 'private-send.php', ['room_id' => $privRoom, 'username' => $u1, 'message' => 'private parent ' . $tag], $t1);
check('private parent is sent', !empty($privParent['json']['success']), $privParent['raw']);
$privPoll1 = api('GET', 'private-poll.php?room_id=' . rawurlencode($privRoom) . '&since=0', null, $t2);
$privParentId = '';
foreach (($privPoll1['json']['messages'] ?? []) as $m) {
    if (($m['message'] ?? '') === 'private parent ' . $tag) { $privParentId = (string) $m['id']; }
}
check('private parent can be read', $privParentId !== '', $privPoll1['raw']);

$privReply = api('POST', 'private-send.php', ['room_id' => $privRoom, 'username' => $u2, 'message' => 'private reply ' . $tag, 'reply_to' => $privParentId], $t2);
check('private reply is accepted', !empty($privReply['json']['success']), $privReply['raw']);
$privPoll2 = api('GET', 'private-poll.php?room_id=' . rawurlencode($privRoom) . '&since=0', null, $t1);
$privReplyRow = null;
foreach (($privPoll2['json']['messages'] ?? []) as $m) {
    if (($m['message'] ?? '') === 'private reply ' . $tag) { $privReplyRow = $m; }
}
check('private poll carries reply metadata', is_array($privReplyRow) && ($privReplyRow['reply']['id'] ?? '') === $privParentId, json_encode($privReplyRow));

$badPrivReply = api('POST', 'private-send.php', ['room_id' => $privRoom, 'username' => $u2, 'message' => 'bad', 'reply_to' => 'missing'], $t2);
check('missing private reply target is rejected', $badPrivReply['status'] === 400, 'status ' . $badPrivReply['status']);

/* ------------------------------------------------------------------ */
note('PWA assets and deep-link wiring');
/* ------------------------------------------------------------------ */

$manifest = http('GET', $BASE . '/manifest.json');
check('manifest is served as JSON', $manifest['status'] === 200 && is_array($manifest['json']), $manifest['raw']);
check('manifest has a standalone display and start URL', ($manifest['json']['display'] ?? '') === 'standalone' && !empty($manifest['json']['start_url']), $manifest['raw']);
check('manifest has 192px and 512px icons', count($manifest['json']['icons'] ?? []) >= 2, $manifest['raw']);

$sw = http('GET', $BASE . '/service-worker.js');
check('service worker is served', $sw['status'] === 200 && str_contains($sw['raw'], "addEventListener('push'"), 'status ' . $sw['status']);
check('service worker handles notification clicks', str_contains($sw['raw'], 'notificationclick'));
$html = http('GET', $BASE . '/messnger.html');
check('HTML links the manifest', str_contains($html['raw'], 'rel="manifest"'));
check('HTML contains reply and pinned UI', str_contains($html['raw'], 'id="reply-bar"') && str_contains($html['raw'], 'id="pinned-bar"') && str_contains($html['raw'], 'id="private-reply-bar"'));
$js = http('GET', $BASE . '/script.js');
check('client implements deep links', str_contains($js['raw'], 'applyDeepLink') && str_contains($js['raw'], "searchParams.set('room'") && str_contains($js['raw'], "url.hash = 'msg='"));
check('client registers push and service worker', str_contains($js['raw'], 'enablePushNotifications') && str_contains($js['raw'], 'serviceWorker.register'));
foreach (['icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
    $img = http('GET', $BASE . '/' . $file);
    $dim = @getimagesize($ROOT . '/' . $file);
    check($file . ' is a valid PNG at the expected size', $img['status'] === 200 && str_starts_with($img['raw'], "\x89PNG") && is_array($dim) && $dim[0] === $size && $dim[1] === $size, $img['status'] . ' ' . json_encode($dim));
}

/* ------------------------------------------------------------------ */
note('Web Push subscription and VAPID encryption');
/* ------------------------------------------------------------------ */

$keyResponse = api('GET', 'push.php?action=key');
$publicKey = (string) ($keyResponse['json']['publicKey'] ?? '');
$publicRaw = b64uDecode($publicKey);
check('push public key endpoint responds', $keyResponse['status'] === 200 && strlen($publicRaw) === 65 && $publicRaw[0] === "\x04", $keyResponse['raw']);

$badSub = api('POST', 'push.php', ['action' => 'subscribe', 'subscription' => ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'x', 'auth' => 'y']]], $t1);
check('insecure push subscription is rejected', $badSub['status'] === 400, 'status ' . $badSub['status']);

require_once $ROOT . '/api/webpush.php';
$client = wp_ec_key_new();
$details = $client ? openssl_pkey_get_details($client) : false;
$clientRaw = ($details && isset($details['ec']['x'], $details['ec']['y'])) ? "\x04" . $details['ec']['x'] . $details['ec']['y'] : '';
$authSecret = random_bytes(16);
$endpoint = 'https://push.example.test/sub/' . $tag;
$subscription = [
    'endpoint' => $endpoint,
    'keys' => ['p256dh' => b64u($clientRaw), 'auth' => b64u($authSecret)],
];
$subscribe = api('POST', 'push.php', ['action' => 'subscribe', 'subscription' => $subscription], $t1);
check('valid push subscription is stored', !empty($subscribe['json']['success']), $subscribe['raw']);
$stored = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE username = ? AND endpoint = ?');
$stored->execute([$u1, $endpoint]);
check('subscription row exists for the user', (int) $stored->fetchColumn() === 1);
$unsubscribe = api('POST', 'push.php', ['action' => 'unsubscribe', 'endpoint' => $endpoint], $t1);
check('push subscription can be removed', !empty($unsubscribe['json']['success']), $unsubscribe['raw']);
$stored->execute([$u1, $endpoint]);
check('subscription row is deleted', (int) $stored->fetchColumn() === 0);

$jwt = wp_vapid_jwt($endpoint);
$jwtParts = explode('.', $jwt);
$jwtHeader = json_decode(b64uDecode($jwtParts[0] ?? ''), true);
$jwtPayload = json_decode(b64uDecode($jwtParts[1] ?? ''), true);
check('VAPID JWT has ES256 header', ($jwtHeader['alg'] ?? '') === 'ES256' && ($jwtHeader['typ'] ?? '') === 'JWT', json_encode($jwtHeader));
check('VAPID JWT has correct audience, expiry, and subject', ($jwtPayload['aud'] ?? '') === 'https://push.example.test' && ($jwtPayload['exp'] ?? 0) > time() && !empty($jwtPayload['sub']), json_encode($jwtPayload));
$signatureOk = false;
if (count($jwtParts) === 3) {
    $der = rawSignatureToDer(b64uDecode($jwtParts[2]));
    $signatureOk = $der !== '' && openssl_verify($jwtParts[0] . '.' . $jwtParts[1], $der, wp_raw_public_pem($publicRaw), OPENSSL_ALGO_SHA256) === 1;
}
check('VAPID JWT signature verifies with the public key', $signatureOk);

$payload = '{"title":"Feature test","body":"notification body"}';
$encrypted = wp_encrypt_payload($payload, $clientRaw, $authSecret);
$body = $encrypted['body'];
$salt = substr($body, 0, 16);
$rs = unpack('N', substr($body, 16, 4))[1] ?? 0;
$idLen = ord($body[20] ?? "\x00");
$localPublic = substr($body, 21, $idLen);
$cipherAndTag = substr($body, 21 + $idLen);
$tag = substr($cipherAndTag, -16);
$ciphertext = substr($cipherAndTag, 0, -16);
$localPem = wp_raw_public_pem($localPublic);
$shared = openssl_pkey_derive($localPem, $client, 32);
$ikm = $shared === false ? '' : hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $clientRaw . $localPublic, $authSecret);
$cek = $ikm === '' ? '' : hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
$nonce = $ikm === '' ? '' : hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
$plain = $cek === '' ? false : openssl_decrypt($ciphertext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
check('aes128gcm header is well formed', $rs === 4096 && $idLen === 65 && strlen($localPublic) === 65 && $localPublic[0] === "\x04", "rs=$rs idLen=$idLen");
check('push payload encrypts and decrypts with the client key', $plain === $payload . "\x02", $plain === false ? 'decrypt failed' : substr((string) $plain, 0, 80));
$invalidSend = wp_send_subscription(['endpoint' => 'http://127.0.0.1/x', 'keys' => ['p256dh' => '', 'auth' => '']], ['title' => 'x']);
check('invalid push subscription is not sent', $invalidSend['ok'] === false);

/* ------------------------------------------------------------------ */
note('Email log transport and password reset flow');
/* ------------------------------------------------------------------ */

$mailDir = $ROOT . '/mail_log';
$before = is_dir($mailDir) ? glob($mailDir . '/mail-*.txt') : [];
$resetRequest = api('POST', 'password.php', ['action' => 'request', 'id' => $u1]);
check('password reset request succeeds', $resetRequest['status'] === 200 && !empty($resetRequest['json']['success']), $resetRequest['raw']);
check('log transport returns a reset link for local testing', !empty($resetRequest['json']['reset_link']), $resetRequest['raw']);
$after = is_dir($mailDir) ? glob($mailDir . '/mail-*.txt') : [];
$newMailFiles = array_values(array_diff($after, $before));
check('a reset email was written to the log', count($newMailFiles) === 1, 'new mail files: ' . count($newMailFiles));
if (count($newMailFiles) === 1) {
    $mail = (string) file_get_contents($newMailFiles[0]);
    check('reset email includes the recipient and reset subject', str_contains($mail, 'To: ' . $u1 . '@example.test') && str_contains($mail, 'Reset your JohnsonChat password'), $mail);
    check('reset email includes the generated link', !empty($resetRequest['json']['reset_link']) && str_contains($mail, (string) $resetRequest['json']['reset_link']), $mail);
}

/* ------------------------------------------------------------------ */
note('Cleanup');
/* ------------------------------------------------------------------ */

try {
    foreach ($createdUsers as $u) {
        $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$u]);
        $pdo->prepare('DELETE FROM tokens WHERE username = ?')->execute([$u]);
        $pdo->prepare('DELETE FROM messages WHERE username = ?')->execute([$u]);
        $pdo->prepare('DELETE FROM push_subscriptions WHERE username = ?')->execute([$u]);
        $pdo->prepare('DELETE FROM blocks WHERE username = ? OR blocked = ?')->execute([$u, $u]);
        $pdo->prepare('DELETE FROM read_state WHERE username = ?')->execute([$u]);
        $pdo->prepare('DELETE FROM rooms WHERE created_by = ?')->execute([$u]);
    }
    foreach ($createdRooms as $r) {
        $pdo->prepare('DELETE FROM messages WHERE room_id = ?')->execute([$r]);
        $pdo->prepare('DELETE FROM room_pins WHERE room_id = ?')->execute([$r]);
        $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$r]);
    }
    foreach ($newMailFiles as $file) { @unlink($file); }
    foreach ($createdPrivateRooms as $rid) {
        @unlink($ROOT . '/private_messages/' . basename($rid) . '.json');
    }
    $privateFile = $ROOT . '/api/private.json';
    if (is_file($privateFile)) {
        $private = json_decode((string) file_get_contents($privateFile), true);
        if (is_array($private)) {
            foreach ($createdPrivateRooms as $rid) { unset($private['rooms'][$rid]); }
            file_put_contents($privateFile, json_encode($private, JSON_PRETTY_PRINT));
        }
    }
    $offlineFile = $ROOT . '/api/offline.json';
    if (is_file($offlineFile)) {
        $offline = json_decode((string) file_get_contents($offlineFile), true);
        if (is_array($offline)) {
            foreach ($createdUsers as $u) { unset($offline[$u]); }
            foreach ($offline as $owner => $list) {
                if (is_array($list)) {
                    $offline[$owner] = array_values(array_filter($list, fn($m) => !in_array(($m['from'] ?? ''), $createdUsers, true)));
                    if (empty($offline[$owner])) { unset($offline[$owner]); }
                }
            }
            file_put_contents($offlineFile, json_encode($offline, JSON_PRETTY_PRINT));
        }
    }
    echo "  cleaned up feature test data\n";
} catch (Throwable $e) {
    echo '  cleanup warning: ' . $e->getMessage() . "\n";
}

echo "----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "All feature checks passed.\n";
exit(0);
