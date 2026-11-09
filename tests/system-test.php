<?php
/**
 * JohnsonChat end-to-end system test.
 *
 * Runs against a live PHP server (default http://127.0.0.1:8088).
 *   php tests/system-test.php
 *   APP_URL=http://127.0.0.1:9000 php tests/system-test.php
 *
 * Creates temporary users/rooms prefixed with a random tag and removes only
 * those entities when it finishes.
 */

declare(strict_types=1);

$BASE = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8088', '/');
$API = $BASE . '/api';
$DBFILE = __DIR__ . '/../api/data/chat.db';
$TMP = sys_get_temp_dir();

$pass = 0;
$fail = 0;
$failures = [];
$createdUsers = [];
$createdRooms = [];
$createdPrivateRooms = [];

function db(): ?PDO
{
    global $DBFILE;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!file_exists($DBFILE)) {
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
function http(string $method, string $url, $body = null, array $headers = [], ?array $multipart = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    } elseif ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [
        'status' => $status,
        'json' => json_decode($raw, true),
        'raw' => $raw,
        'headers' => [],
    ];
}

function api(string $method, string $path, $body = null, ?string $token = null, array $headers = [], ?array $multipart = null): array
{
    global $API;
    $url = str_starts_with($path, 'http') ? $path : $API . '/' . ltrim($path, '/');
    if ($token !== null) {
        if ($method === 'GET') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
        } elseif (is_array($body) && $multipart === null) {
            $body['token'] = $token;
        }
    }
    return http($method, $url, $multipart === null ? $body : null, $headers, $multipart);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        $failures[] = $name . ($detail !== '' ? "  [$detail]" : '');
        echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

function note(string $text): void
{
    echo "\n== $text ==\n";
}

function login(string $username, string $password): ?string
{
    $r = api('POST', 'login.php', ['username' => $username, 'password' => $password]);
    return is_array($r['json']) && !empty($r['json']['token']) ? (string) $r['json']['token'] : null;
}

function makeUser(string $username, string $password, string $role = 'user'): void
{
    global $createdUsers;
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('No database available');
    }
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, username, password, is_active, role, created_at)
        VALUES (?, ?, ?, ?, 1, ?, datetime('now'))");
    $stmt->execute(['Test ' . $username, $username . '@example.test', $username, password_hash($password, PASSWORD_DEFAULT), $role]);
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

/* ------------------------------------------------------------------ */
/* Bootstrap                                                          */
/* ------------------------------------------------------------------ */

echo "JohnsonChat system test against $BASE\n";

if (!is_dir(dirname($DBFILE))) {
    fwrite(STDERR, "No api/data directory - run the setup wizard first.\n");
    exit(2);
}

$health = api('GET', 'health.php');
check('health endpoint responds', $health['status'] === 200 && is_array($health['json']));
check('session helpers are loaded', !empty($health['json']['session_check_defined']) && !empty($health['json']['token_sql_defined']));

// Clear rate-limit buckets so repeated runs are deterministic.
$pdo = db();
if ($pdo) {
    try { $pdo->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
}

$adminToken = login('admin', 'admin123');
if (!$adminToken) {
    $setup = api('POST', 'setup.php', ['action' => 'quick_setup', 'username' => 'admin', 'password' => 'admin123']);
    $adminToken = login('admin', 'admin123');
}
if (!$adminToken) {
    fwrite(STDERR, "Cannot sign in as admin/admin123 and quick setup did not help.\n");
    exit(2);
}
check('admin can sign in', $adminToken !== null);

$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$u1 = 't' . $tag . 'a';
$u2 = 't' . $tag . 'b';
$u3 = 't' . $tag . 'c';
$u4 = 't' . $tag . 'd';
$u5 = 't' . $tag . 'e';
$pw = 'Passw0rd!' . $tag;

makeUser($u1, $pw);
makeUser($u2, $pw);
makeUser($u3, $pw);
makeUser($u4, $pw);
makeUser($u5, $pw);

$t1 = login($u1, $pw);
$t2 = login($u2, $pw);
$t3 = login($u3, $pw);
check('test users can sign in', $t1 && $t2 && $t3);

/* ------------------------------------------------------------------ */
note('Authentication and signup');
/* ------------------------------------------------------------------ */

$signupUser = 't' . $tag . 's';
$signup = api('POST', 'signup.php', [
    'fullName' => 'Signup Test',
    'email' => $signupUser . '@example.test',
    'username' => $signupUser,
    'password' => 'pass1234',
]);
if ($signup['status'] === 429) {
    check('signup creates an account', false, 'rate limited by a previous run');
} else {
    check('signup creates an account', $signup['status'] === 200 && !empty($signup['json']['success']), $signup['raw']);
    if (!empty($signup['json']['success'])) {
        $createdUsers[] = $signupUser;
    }
}
$dup = api('POST', 'signup.php', [
    'fullName' => 'Dup',
    'email' => 'dup' . $tag . '@example.test',
    'username' => $signupUser,
    'password' => 'pass1234',
]);
check('duplicate username is rejected', $dup['status'] === 409, 'status ' . $dup['status']);
$badEmail = api('POST', 'signup.php', ['email' => 'not-an-email', 'username' => 'x' . $tag, 'password' => 'pass1234']);
check('invalid email is rejected', $badEmail['status'] === 400, 'status ' . $badEmail['status']);
$shortPw = api('POST', 'signup.php', ['email' => 's' . $tag . '@example.test', 'username' => 'y' . $tag, 'password' => '1']);
check('short password is rejected', $shortPw['status'] === 400, 'status ' . $shortPw['status']);

$badLogin = api('POST', 'login.php', ['username' => $u1, 'password' => 'wrong-password']);
check('wrong password is rejected', $badLogin['status'] === 401, 'status ' . $badLogin['status']);

$verify = api('POST', 'verify.php', ['token' => $t1]);
check('valid token verifies', $verify['status'] === 200 && !empty($verify['json']['valid']) && $verify['json']['username'] === $u1);
$badVerify = api('POST', 'verify.php', ['token' => 'nope']);
check('invalid token does not verify', $badVerify['status'] === 200 && empty($badVerify['json']['valid']));

$noAuth = api('GET', 'blocks.php');
check('private endpoint rejects missing token', $noAuth['status'] === 403, 'status ' . $noAuth['status']);

/* ------------------------------------------------------------------ */
note('Rooms and public messaging');
/* ------------------------------------------------------------------ */

$rooms = api('GET', 'rooms.php');
check('rooms list includes General', is_array($rooms['json']['rooms'] ?? null) && in_array('general', array_column($rooms['json']['rooms'], 'id'), true));

$roomName = 'Test Room ' . $tag;
$roomCreate = api('POST', 'rooms.php', ['name' => $roomName], $t1);
$testRoom = $roomCreate['json']['room']['id'] ?? '';
if ($testRoom !== '') {
    $createdRooms[] = $testRoom;
}
check('authenticated user can create a room', $roomCreate['status'] === 200 && $testRoom !== '', $roomCreate['raw']);
$dupRoom = api('POST', 'rooms.php', ['name' => $roomName], $t1);
check('duplicate room is rejected', $dupRoom['status'] === 409, 'status ' . $dupRoom['status']);
$foreignOrigin = api('POST', 'rooms.php', ['name' => 'Evil ' . $tag], $t1, ['Origin: http://evil.example']);
check('foreign-origin write is rejected', $foreignOrigin['status'] === 403, 'status ' . $foreignOrigin['status']);

$noTokenSend = api('POST', 'send.php', ['username' => $u1, 'message' => 'hello', 'room_id' => $testRoom]);
check('send without token is rejected', $noTokenSend['status'] === 403, 'status ' . $noTokenSend['status']);
$mismatch = api('POST', 'send.php', ['username' => $u1, 'message' => 'hello', 'room_id' => $testRoom], $t2);
check('send with another user\'s token is rejected', $mismatch['status'] === 403, 'status ' . $mismatch['status']);

$firstText = 'hello system test ' . $tag;
$sent = api('POST', 'send.php', ['username' => $u1, 'message' => $firstText, 'room_id' => $testRoom], $t1);
check('user can send a message', $sent['status'] === 200 && !empty($sent['json']['success']), $sent['raw']);
$msgId = $sent['json']['message']['id'] ?? '';
$sent2 = api('POST', 'send.php', ['username' => $u2, 'message' => 'reply ' . $tag, 'room_id' => $testRoom], $t2);
check('second user can send a message', $sent2['status'] === 200, $sent2['raw']);
$tooLong = api('POST', 'send.php', ['username' => $u1, 'message' => str_repeat('x', 501), 'room_id' => $testRoom], $t1);
check('over-length message is rejected', $tooLong['status'] === 400, 'status ' . $tooLong['status']);
$badRoom = api('POST', 'send.php', ['username' => $u1, 'message' => 'hi', 'room_id' => 'does-not-exist-' . $tag], $t1);
check('message to unknown room is rejected', $badRoom['status'] === 404, 'status ' . $badRoom['status']);

$poll = pollMessages($testRoom, $t2);
$found = false;
foreach (($poll['messages'] ?? []) as $m) {
    if (($m['id'] ?? '') === $msgId) { $found = true; }
}
check('poll returns newly sent messages', $found);
check('poll returns a server cursor', isset($poll['server_time']) && $poll['server_time'] > 0);
check('message payload includes reactions as an object', isset($poll['messages'][0]['reactions']) && is_array($poll['messages'][0]['reactions']));

/* Reactions */
$rx = api('POST', 'react.php', ['message_id' => $msgId, 'emoji' => '👍', 'kind' => 'public', 'room_id' => $testRoom], $t2);
check('a reaction can be added', !empty($rx['json']['success']), $rx['raw']);
check('reaction is recorded against the user', isset($rx['json']['reactions']['👍']) && in_array($u2, $rx['json']['reactions']['👍'], true), $rx['raw']);
$rxBad = api('POST', 'react.php', ['message_id' => $msgId, 'emoji' => '💩', 'kind' => 'public', 'room_id' => $testRoom], $t2);
check('unsupported reaction is rejected', $rxBad['status'] === 400, 'status ' . $rxBad['status']);
$rxOff = api('POST', 'react.php', ['message_id' => $msgId, 'emoji' => '👍', 'kind' => 'public', 'room_id' => $testRoom], $t2);
check('reacting twice removes the reaction', isset($rxOff['json']['reactions']) && !isset($rxOff['json']['reactions']['👍']), $rxOff['raw']);

/* Edit / delete */
$edit = api('POST', 'message-action.php', ['action' => 'edit', 'message_id' => $msgId, 'kind' => 'public', 'room_id' => $testRoom, 'message' => 'edited ' . $tag], $t1);
check('author can edit their message', !empty($edit['json']['success']), $edit['raw']);
$editOther = api('POST', 'message-action.php', ['action' => 'edit', 'message_id' => $msgId, 'kind' => 'public', 'room_id' => $testRoom, 'message' => 'hacked'], $t2);
check('another user cannot edit the message', $editOther['status'] === 403, 'status ' . $editOther['status']);
$pollEdit = pollMessages($testRoom, $t2, 0);
$edited = null;
foreach (($pollEdit['messages'] ?? []) as $m) {
    if (($m['id'] ?? '') === $msgId) { $edited = $m; }
}
check('edits propagate through poll', $edited && ($edited['message'] ?? '') === 'edited ' . $tag && (int) ($edited['edited'] ?? 0) === 1, json_encode($edited));

$deleted = api('POST', 'message-action.php', ['action' => 'delete', 'message_id' => $msgId, 'kind' => 'public', 'room_id' => $testRoom], $t1);
check('author can delete their message', !empty($deleted['json']['success']), $deleted['raw']);
$pollDel = pollMessages($testRoom, $t2, 0);
$tomb = null;
foreach (($pollDel['messages'] ?? []) as $m) {
    if (($m['id'] ?? '') === $msgId) { $tomb = $m; }
}
check('deletes propagate as a tombstone', $tomb && (int) ($tomb['deleted'] ?? 0) === 1, json_encode($tomb));

/* History pagination: newest messages must win the 100-row cap. */
$pgRoom = 'pg' . $tag;
$ins = $pdo->prepare("INSERT INTO rooms (id, name, created_by, created_at, last_activity) VALUES (?, ?, 'system', datetime('now'), datetime('now'))");
$ins->execute([$pgRoom, 'Pagination ' . $tag]);
$createdRooms[] = $pgRoom;
$insert = $pdo->prepare('INSERT INTO messages (id, username, message, room_id, time, timestamp, edited, edit_ts, deleted, reactions, file_token) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, \'\')');
$baseTs = microtime(true) - 5000;
for ($i = 1; $i <= 120; $i++) {
    $insert->execute(['pg' . $tag . '_' . $i, $u1, 'history message ' . $i, $pgRoom, '00:00', $baseTs + $i, '{}']);
}
$pgPoll = pollMessages($pgRoom, $t1);
$pgTexts = array_column($pgPoll['messages'] ?? [], 'message');
check('history load returns at most 100 messages', count($pgTexts) <= 100, 'got ' . count($pgTexts));
check('history load returns the newest messages, not the oldest', in_array('history message 120', $pgTexts, true), 'first=' . ($pgTexts[0] ?? 'none'));
check('history load omits messages beyond the window', !in_array('history message 1', $pgTexts, true));

/* Cursor must account for edits, otherwise the same edit replays forever. */
$cursorPoll = pollMessages($testRoom, $t2);
$cursor = (float) ($cursorPoll['server_time'] ?? 0);
$edit2 = api('POST', 'message-action.php', ['action' => 'edit', 'message_id' => ($sent2['json']['message']['id'] ?? ''), 'kind' => 'public', 'room_id' => $testRoom, 'message' => 'reply edited ' . $tag], $t2);
$afterEdit = pollMessages($testRoom, $t2, $cursor);
$cursor2 = (float) ($afterEdit['server_time'] ?? 0);
$repeat = pollMessages($testRoom, $t2, $cursor2);
$editDelivered = false;
foreach (($afterEdit['messages'] ?? []) as $m) {
    if (($m['message'] ?? '') === 'reply edited ' . $tag) { $editDelivered = true; }
}
check('edit after a cursor is delivered once', $editDelivered);
check('edit does not replay on the next poll', count($repeat['messages'] ?? []) === 0, 'replayed ' . count($repeat['messages'] ?? []));

/* Search */
$search = api('GET', 'search.php?q=' . rawurlencode('reply edited ' . $tag), null, $t1);
$pubSearch = is_array($search['json']['public'] ?? null) ? $search['json']['public'] : [];
check('search finds public messages', count($pubSearch) > 0);

/* Read state and typing */
$rs = api('POST', 'read-state.php', ['scope' => 'room', 'room_id' => $testRoom], $t1);
check('read state can be recorded', !empty($rs['json']['success']), $rs['raw']);
$rsGet = api('GET', 'read-state.php', null, $t1);
check('read state returns per-room unread data', isset($rsGet['json']['rooms']) && is_array($rsGet['json']['rooms']), $rsGet['raw']);
$rsRoom = null;
foreach (($rsGet['json']['rooms'] ?? []) as $r) {
    if (($r['room_id'] ?? '') === $testRoom) { $rsRoom = $r; }
}
check('read state includes the requested room', $rsRoom !== null && isset($rsRoom['unread']), $rsGet['raw']);

$typing = api('POST', 'typing.php', ['kind' => 'public', 'room_id' => $testRoom], $t1);
check('typing signal accepted', !empty($typing['json']['success']), $typing['raw']);
$typingPoll = pollMessages($testRoom, $t2);
check('typing indicator is visible to the other user', in_array($u1, $typingPoll['typing'] ?? [], true), json_encode($typingPoll['typing'] ?? []));

/* ------------------------------------------------------------------ */
note('Private chat');
/* ------------------------------------------------------------------ */

$privRoom = roomIdFor($u1, $u2);
$req = api('POST', 'private-request.php', ['target' => $u2], $t1);
check('private chat request is created', !empty($req['json']['success']), $req['raw']);
$pc = api('GET', 'private-check.php?username=' . rawurlencode($u2), null, $t2);
$pending = array_filter($pc['json']['requests'] ?? [], fn($p) => strtolower((string) ($p['from'] ?? '')) === strtolower($u1));
check('recipient sees the pending request', count($pending) > 0, $pc['raw']);

$earlySend = api('POST', 'private-send.php', ['room_id' => $privRoom, 'username' => $u1, 'message' => 'too early'], $t1);
check('cannot send before the request is accepted', $earlySend['status'] === 403, 'status ' . $earlySend['status']);
$respond = api('POST', 'private-respond.php', ['room_id' => $privRoom, 'accept' => true], $t2);
check('recipient can accept the request', !empty($respond['json']['success']) && ($respond['json']['room_id'] ?? '') === $privRoom, $respond['raw']);
$createdPrivateRooms[] = $privRoom;

$secret = 'very secret ' . $tag;
$psend = api('POST', 'private-send.php', ['room_id' => $privRoom, 'username' => $u1, 'message' => $secret], $t1);
check('members can send private messages', !empty($psend['json']['success']), $psend['raw']);
$ppoll = api('GET', 'private-poll.php?room_id=' . rawurlencode($privRoom) . '&since=0', null, $t2);
check('members can read private messages', count(array_filter($ppoll['json']['messages'] ?? [], fn($m) => ($m['message'] ?? '') === $secret)) === 1, $ppoll['raw']);
$ppoll3 = api('GET', 'private-poll.php?room_id=' . rawurlencode($privRoom) . '&since=0', null, $t3);
check('non-members cannot read the private room', $ppoll3['status'] === 403, 'status ' . $ppoll3['status']);

$pm = null;
foreach (($ppoll['json']['messages'] ?? []) as $m) {
    if (($m['message'] ?? '') === $secret) { $pm = $m; }
}
$pmId = $pm['id'] ?? '';
$prx3 = api('POST', 'react.php', ['message_id' => $pmId, 'emoji' => '❤️', 'kind' => 'private', 'room_id' => $privRoom], $t3);
check('non-members cannot react in the private room', $prx3['status'] === 403, 'status ' . $prx3['status'] . ' ' . $prx3['raw']);
$prx = api('POST', 'react.php', ['message_id' => $pmId, 'emoji' => '❤️', 'kind' => 'private', 'room_id' => $privRoom], $t2);
check('members can react in the private room', !empty($prx['json']['success']), $prx['raw']);

$pEditOther = api('POST', 'message-action.php', ['action' => 'edit', 'message_id' => $pmId, 'kind' => 'private', 'room_id' => $privRoom, 'message' => 'changed by peer'], $t2);
check('private messages cannot be edited by the peer', $pEditOther['status'] === 403, 'status ' . $pEditOther['status']);

$privateSearchNoToken = api('GET', 'search.php?q=' . rawurlencode($secret));
$leakedPrivate = $privateSearchNoToken['json']['private'] ?? [];
check('anonymous search does not leak private messages', count($leakedPrivate) === 0, 'leaked ' . count($leakedPrivate));
$privateSearch3 = api('GET', 'search.php?q=' . rawurlencode($secret), null, $t3);
check('non-members cannot search a private room', count($privateSearch3['json']['private'] ?? []) === 0);
$privateSearch1 = api('GET', 'search.php?q=' . rawurlencode($secret), null, $t1);
check('members can search their private room', count($privateSearch1['json']['private'] ?? []) > 0);

/* Read receipt */
$readPrivate = api('POST', 'read-state.php', ['scope' => 'private', 'room_id' => $privRoom], $t2);
check('private read receipt accepted', !empty($readPrivate['json']['success']), $readPrivate['raw']);

/* Unverified cancellation of someone else's pending request */
$u1u3Room = roomIdFor($u1, $u3);
$req13 = api('POST', 'private-request.php', ['target' => $u3], $t1);
check('second private request is pending', !empty($req13['json']['success']), $req13['raw']);
$cancelByOutsider = api('POST', 'private-end.php', ['room_id' => $u1u3Room], $t2);
$pc3After = api('GET', 'private-check.php?username=' . rawurlencode($u3), null, $t3);
$stillPending = array_filter($pc3After['json']['requests'] ?? [], fn($p) => strtolower((string) ($p['from'] ?? '')) === strtolower($u1));
check('outsiders cannot cancel another pair\'s pending request', count($stillPending) > 0, 'outsider status ' . $cancelByOutsider['status']);
$respond13 = api('POST', 'private-respond.php', ['room_id' => $u1u3Room, 'accept' => false], $t3);
check('recipient can decline the request', !empty($respond13['json']['declined']), $respond13['raw']);

/* ------------------------------------------------------------------ */
note('Friends and blocks');
/* ------------------------------------------------------------------ */

$friendAdd = api('POST', 'friends.php', ['friend' => $u2], $t1);
check('friend can be added', !empty($friendAdd['json']['success']), $friendAdd['raw']);
$fl = api('GET', 'friends.php?username=' . rawurlencode($u1));
$names = array_column($fl['json']['friends'] ?? [], 'username');
check('friend list includes the friend', in_array($u2, $names, true), $fl['raw']);

$friendsWithWrongToken = api('GET', 'friends.php?username=' . rawurlencode($u1), null, $t3);
$item = null;
foreach (($friendsWithWrongToken['json']['friends'] ?? []) as $f) {
    if (($f['username'] ?? '') === $u2) { $item = $f; }
}
check('another user cannot read friend unread/preview details', $item !== null && !array_key_exists('unread', $item) && !array_key_exists('preview', $item), json_encode($item));

$block = api('POST', 'blocks.php', ['action' => 'block', 'target' => $u1], $t2);
check('user can block another user', !empty($block['json']['success']), $block['raw']);
$blockedList = api('GET', 'blocks.php', null, $t2);
check('block list contains the blocked user', in_array($u1, $blockedList['json']['blocked'] ?? [], true), $blockedList['raw']);

api('POST', 'send.php', ['username' => $u1, 'message' => 'blocked message ' . $tag, 'room_id' => $testRoom], $t1);
$blockedPoll = pollMessages($testRoom, $t2);
$sawBlocked = false;
foreach (($blockedPoll['messages'] ?? []) as $m) {
    if (($m['message'] ?? '') === 'blocked message ' . $tag) { $sawBlocked = true; }
}
check('blocked users\' messages are hidden', !$sawBlocked);

$blockedOffline = api('POST', 'send-offline.php', ['target' => $u2, 'message' => 'sneaking past the block'], $t1);
check('blocked users cannot send offline messages', $blockedOffline['status'] === 403, 'status ' . $blockedOffline['status'] . ' ' . $blockedOffline['raw']);

$pSendBlocked = api('POST', 'private-send.php', ['room_id' => $privRoom, 'username' => $u1, 'message' => 'blocked private'], $t1);
check('blocked users cannot send private messages', $pSendBlocked['status'] === 403, 'status ' . $pSendBlocked['status']);

$unblock = api('POST', 'blocks.php', ['action' => 'unblock', 'target' => $u1], $t2);
check('user can unblock', !empty($unblock['json']['success']), $unblock['raw']);

/* ------------------------------------------------------------------ */
note('Uploads, avatars and profiles');
/* ------------------------------------------------------------------ */

$pngPath = $TMP . '/jc_test_' . $tag . '.png';
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

$up = api('POST', 'upload.php', null, null, [], [
    'file' => new CURLFile($pngPath, 'image/png', 'tiny.png'),
    'username' => $u1,
    'room_id' => $testRoom,
    'token' => $t1,
]);
$fileToken = $up['json']['token'] ?? '';
check('allowed image upload succeeds', !empty($up['json']['success']) && $fileToken !== '', $up['raw']);

if ($fileToken !== '') {
    $dl = http('GET', $API . '/download.php?token=' . rawurlencode($fileToken));
    check('uploaded file can be downloaded', $dl['status'] === 200 && str_starts_with($dl['raw'], "\x89PNG"));
    $dlBad = http('GET', $API . '/download.php?token=deadbeef');
    check('unknown download token returns 404', $dlBad['status'] === 404, 'status ' . $dlBad['status']);
}

$spoofPath = $TMP . '/jc_spoof_' . $tag . '.png';
file_put_contents($spoofPath, '<html><script>alert(1)</script></html>');
$spoof = api('POST', 'upload.php', null, null, [], [
    'file' => new CURLFile($spoofPath, 'image/png', 'evil.png'),
    'username' => $u1,
    'room_id' => $testRoom,
    'token' => $t1,
]);
check('spoofed file content is rejected', empty($spoof['json']['success']), $spoof['raw']);

$avatar = api('POST', 'profile.php', null, null, [], [
    'avatar' => new CURLFile($pngPath, 'image/png', 'avatar.png'),
    'token' => $t1,
]);
check('avatar upload succeeds', !empty($avatar['json']['success']), $avatar['raw']);
$avatarGet = http('GET', $API . '/avatar.php?username=' . rawurlencode($u1));
check('avatar is served as an image', $avatarGet['status'] === 200 && str_starts_with($avatarGet['raw'], "\x89PNG"));

$profile = api('POST', 'profile.php', ['full_name' => 'Alice Tester', 'city' => 'Lexington', 'state' => 'KY', 'age' => '29', 'description' => 'Testing the system.'], $t1);
check('profile can be updated', !empty($profile['json']['success']), $profile['raw']);
$profileGet = api('GET', 'profile.php?username=' . rawurlencode($u1));
check('profile changes are readable', ($profileGet['json']['user']['full_name'] ?? '') === 'Alice Tester' && ($profileGet['json']['user']['city'] ?? '') === 'Lexington', $profileGet['raw']);
$badAge = api('POST', 'profile.php', ['age' => '999'], $t1);
check('invalid age is rejected', $badAge['status'] === 400, 'status ' . $badAge['status']);

/* ------------------------------------------------------------------ */
note('Offline messages');
/* ------------------------------------------------------------------ */

$offline = api('POST', 'send-offline.php', ['target' => $u4, 'message' => 'offline hello ' . $tag], $t1);
check('offline message can be sent', !empty($offline['json']['success']), $offline['raw']);
$t4 = login($u4, $pw);
$offlineCheck = api('GET', 'offline-check.php?username=' . rawurlencode($u4), null, $t4);
$snippets = array_map(fn($m) => $m['message_snippet'] ?? '', $offlineCheck['json']['messages'] ?? []);
check('recipient sees the offline message', in_array('offline hello ' . $tag, $snippets, true), $offlineCheck['raw']);
$clear = api('POST', 'clear-offline.php', [], $t4);
check('offline inbox can be cleared', !empty($clear['json']['success']), $clear['raw']);
$offlineAfter = api('GET', 'offline-check.php?username=' . rawurlencode($u4), null, $t4);
check('cleared inbox is empty', count($offlineAfter['json']['messages'] ?? []) === 0);

/* ------------------------------------------------------------------ */
note('Moderation');
/* ------------------------------------------------------------------ */

$nonAdmin = api('POST', 'admin-action.php', ['target' => $u2, 'action' => 'mute', 'duration' => '1h'], $t1);
check('regular users cannot moderate', $nonAdmin['status'] === 403, 'status ' . $nonAdmin['status']);

$mute = api('POST', 'admin-action.php', ['target' => $u1, 'action' => 'mute', 'duration' => '1h'], $adminToken);
check('admin can mute a user', !empty($mute['json']['success']), $mute['raw']);
$mutedSend = api('POST', 'send.php', ['username' => $u1, 'message' => 'muted?', 'room_id' => $testRoom], $t1);
check('muted user cannot send', $mutedSend['status'] === 403, 'status ' . $mutedSend['status']);

$unmute = api('POST', 'admin-action.php', ['target' => $u1, 'action' => 'unmute'], $adminToken);
check('admin can unmute a user', !empty($unmute['json']['success']) && empty($unmute['json']['error']), $unmute['raw']);
$unmutedSend = api('POST', 'send.php', ['username' => $u1, 'message' => 'unmuted ' . $tag, 'room_id' => $testRoom], $t1);
check('unmuted user can send again', $unmutedSend['status'] === 200, 'status ' . $unmutedSend['status']);

$announce = api('POST', 'rooms.php', ['name' => 'Announcements ' . $tag, 'read_only' => true], $adminToken);
$announceRoom = $announce['json']['room']['id'] ?? '';
if ($announceRoom !== '') {
    $createdRooms[] = $announceRoom;
}
check('staff can create an announcement room', !empty($announce['json']['room']['read_only']), $announce['raw']);
$announceUserCreate = api('POST', 'rooms.php', ['name' => 'Nope ' . $tag, 'read_only' => true], $t1);
check('regular users cannot create announcement rooms', $announceUserCreate['status'] === 403, 'status ' . $announceUserCreate['status']);
$announceSendUser = api('POST', 'send.php', ['username' => $u1, 'message' => 'nope', 'room_id' => $announceRoom], $t1);
check('read-only room rejects regular users', $announceSendUser['status'] === 403, 'status ' . $announceSendUser['status']);
$announceSendAdmin = api('POST', 'send.php', ['username' => 'admin', 'message' => 'Announcement ' . $tag, 'room_id' => $announceRoom], $adminToken);
check('staff can post in the announcement room', $announceSendAdmin['status'] === 200, $announceSendAdmin['raw']);

/* ------------------------------------------------------------------ */
note('Games, calls and the dungeon');
/* ------------------------------------------------------------------ */

$gameSave = api('POST', 'games.php', ['room_id' => $privRoom, 'game' => 'tictactoe', 'action' => 'save', 'state' => ['board' => array_fill(0, 9, null), 'currentPlayer' => 'X', 'winner' => null]], $t1);
check('members can save game state', !empty($gameSave['json']['ok']), $gameSave['raw']);
$gameGet = api('GET', 'games.php?room_id=' . rawurlencode($privRoom) . '&game=tictactoe', null, $t1);
check('members can read game state', !empty($gameGet['json']['ok']) && is_array($gameGet['json']['state']), $gameGet['raw']);
$gameOutsider = api('GET', 'games.php?room_id=' . rawurlencode($privRoom) . '&game=tictactoe', null, $t3);
check('non-members cannot read game state', $gameOutsider['status'] === 403, 'status ' . $gameOutsider['status']);
$gameBadType = api('GET', 'games.php?room_id=' . rawurlencode($privRoom) . '&game=chess', null, $t1);
check('unknown game type is rejected', $gameBadType['status'] === 400, 'status ' . $gameBadType['status']);

$ring = api('POST', 'call.php', ['type' => 'ring', 'room_id' => $privRoom, 'from' => $u1, 'to' => $u2, 'call_id' => 'call' . $tag], $t1);
check('call ring can be sent', !empty($ring['json']['success']), $ring['raw']);
$callEvents = api('GET', 'call.php?room_id=' . rawurlencode($privRoom) . '&username=' . rawurlencode($u2) . '&since=0', null, $t2);
check('members can read call signaling', count($callEvents['json']['events'] ?? []) > 0, $callEvents['raw']);
$callAnon = api('GET', 'call.php?room_id=' . rawurlencode($privRoom) . '&username=' . rawurlencode($u2) . '&since=0');
check('call signaling requires a token', $callAnon['status'] === 403, 'status ' . $callAnon['status']);

$dndCreate = api('POST', 'dnd.php', ['action' => 'create', 'arg' => 'fighter', 'room_id' => $privRoom, 'username' => $u1], $t1);
check('dungeon hero can be created', !empty($dndCreate['json']['ok']), $dndCreate['raw']);
$dndExplore = api('POST', 'dnd.php', ['action' => 'explore', 'room_id' => $privRoom, 'username' => $u1], $t1);
check('dungeon can be explored', !empty($dndExplore['json']['ok']) && ($dndExplore['json']['status'] ?? '') === 'combat', $dndExplore['raw']);
$dndOutsider = api('POST', 'dnd.php', ['action' => 'explore', 'room_id' => $privRoom, 'username' => $u3], $t3);
check('non-members cannot play the dungeon', $dndOutsider['status'] === 403, 'status ' . $dndOutsider['status']);

/* ------------------------------------------------------------------ */
note('Password reset, logout and account deletion');
/* ------------------------------------------------------------------ */

$resetReq = api('POST', 'password.php', ['action' => 'request', 'id' => $u4]);
$resetLink = $resetReq['json']['reset_link'] ?? '';
check('password reset link is generated', $resetLink !== '', $resetReq['raw']);
$resetToken = '';
if ($resetLink !== '') {
    parse_str((string) parse_url($resetLink, PHP_URL_QUERY), $qs);
    $resetToken = (string) ($qs['token'] ?? '');
}
check('reset link contains a token', $resetToken !== '');
$reset = api('POST', 'password.php', ['action' => 'reset', 'token' => $resetToken, 'password' => 'newpass' . $tag]);
check('password can be reset with the token', !empty($reset['json']['success']), $reset['raw']);
$oldLogin = login($u4, $pw);
check('old password stops working after reset', $oldLogin === null);
$newLogin = login($u4, 'newpass' . $tag);
check('new password works after reset', $newLogin !== null);

$logout = api('POST', 'logout.php', [], $newLogin);
check('logout succeeds', !empty($logout['json']['success']), $logout['raw']);
$afterLogout = api('POST', 'verify.php', ['token' => $newLogin]);
check('logged-out token is invalid', empty($afterLogout['json']['valid']));

$t5 = login($u5, $pw);
$delWrong = api('POST', 'delete-account.php', ['password' => 'wrong'], $t5);
check('account deletion requires the password', $delWrong['status'] === 403, 'status ' . $delWrong['status']);
$del = api('POST', 'delete-account.php', ['password' => $pw], $t5);
check('account can be deleted with the password', !empty($del['json']['success']), $del['raw']);
check('deleted account can no longer sign in', login($u5, $pw) === null);

$setupAdminAgain = api('POST', 'setup-admin.php', ['username' => 'newadmin' . $tag, 'password' => 'pass1234']);
check('setup cannot create a second admin', $setupAdminAgain['status'] === 403, 'status ' . $setupAdminAgain['status']);

/* ------------------------------------------------------------------ */
note('Cleanup');
/* ------------------------------------------------------------------ */

if ($pdo) {
    try {
        foreach ($createdUsers as $u) {
            $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$u]);
            $pdo->prepare('DELETE FROM tokens WHERE username = ?')->execute([$u]);
            $pdo->prepare('DELETE FROM messages WHERE username = ?')->execute([$u]);
            $pdo->prepare('DELETE FROM blocks WHERE username = ? OR blocked = ?')->execute([$u, $u]);
            $pdo->prepare('DELETE FROM read_state WHERE username = ?')->execute([$u]);
            $pdo->prepare('DELETE FROM rooms WHERE created_by = ?')->execute([$u]);
        }
        foreach ($createdRooms as $r) {
            $pdo->prepare('DELETE FROM messages WHERE room_id = ?')->execute([$r]);
            $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$r]);
            $pdo->prepare('DELETE FROM read_state WHERE room_id = ?')->execute([$r]);
        }
        $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$pgRoom]);
        // friend/offline/private files: remove only entries owned by test users.
        foreach (['friends.json', 'private.json', 'offline.json', 'active_users.json'] as $file) {
            $path = dirname($DBFILE) . '/' . $file;
            if (!file_exists($path)) { continue; }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) { continue; }
            if ($file === 'friends.json') {
                foreach ($createdUsers as $u) { unset($data[$u]); }
                foreach ($data as $owner => $list) {
                    if (is_array($list)) {
                        $data[$owner] = array_values(array_filter($list, fn($f) => !in_array($f, $createdUsers, true)));
                    }
                }
            } elseif ($file === 'private.json') {
                foreach ($data['rooms'] ?? [] as $rid => $room) {
                    if (in_array($rid, $createdPrivateRooms, true)) { unset($data['rooms'][$rid]); }
                }
                $data['pending'] = array_values(array_filter($data['pending'] ?? [], fn($p) => !in_array($p['from'] ?? '', $createdUsers, true) && !in_array($p['to'] ?? '', $createdUsers, true)));
                $data['file_notify'] = array_values(array_filter($data['file_notify'] ?? [], fn($n) => !in_array($n['from'] ?? '', $createdUsers, true)));
                $data['denied'] = array_values(array_filter($data['denied'] ?? [], fn($d) => !in_array($d['from'] ?? '', $createdUsers, true) && !in_array($d['to'] ?? '', $createdUsers, true)));
            } elseif ($file === 'offline.json') {
                foreach ($createdUsers as $u) { unset($data[$u]); }
                foreach ($data as $owner => $list) {
                    if (is_array($list)) {
                        $data[$owner] = array_values(array_filter($list, fn($m) => !in_array($m['from'] ?? '', $createdUsers, true)));
                        if (empty($data[$owner])) { unset($data[$owner]); }
                    }
                }
            } elseif ($file === 'active_users.json') {
                $data = array_values(array_filter($data, fn($u) => !in_array($u, $createdUsers, true)));
            }
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        }
        foreach ($createdPrivateRooms as $rid) {
            $f = __DIR__ . '/../private_messages/' . basename($rid) . '.json';
            if (is_file($f)) { @unlink($f); }
            $g = __DIR__ . '/../api/data/dnd/' . md5($rid) . '.json';
            if (is_file($g)) { @unlink($g); }
            $h = __DIR__ . '/../api/data/games/' . md5($rid) . '_tictactoe.json';
            if (is_file($h)) { @unlink($h); }
        }
        foreach ($createdUsers as $u) {
            foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
                $a = __DIR__ . '/../storage/avatars/' . $u . '.' . $ext;
                if (is_file($a)) { @unlink($a); }
            }
        }
        echo "  cleaned up test users, rooms and private chat files\n";
    } catch (Throwable $e) {
        echo '  cleanup warning: ' . $e->getMessage() . "\n";
    }
}
@unlink($pngPath);
@unlink($spoofPath);

/* ------------------------------------------------------------------ */
echo "\n----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "All checks passed.\n";
exit(0);
