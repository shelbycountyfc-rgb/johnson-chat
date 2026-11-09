<?php

/**
 * Shared helpers for the feature additions.
 */

/** True if $viewer has blocked $other (either direction lookup via table). */
function user_is_blocked($pdo, string $viewer, string $other): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM blocks WHERE LOWER(username) = LOWER(?) AND LOWER(blocked) = LOWER(?) LIMIT 1');
    $stmt->execute([$viewer, $other]);
    return (bool) $stmt->fetch();
}

/** True if either user in a private room has blocked the other. */
function private_pair_blocked($pdo, string $a, string $b): bool
{
    return user_is_blocked($pdo, $a, $b) || user_is_blocked($pdo, $b, $a);
}

/**
 * True only when $roomId is a private room that exists and lists $username
 * as a member. A room is created when a chat request is accepted, or by
 * send-offline.php; guessing the pair id is not enough to read or write it.
 */
function is_private_room_member(string $roomId, string $username): bool
{
    $roomId = basename(trim($roomId));
    if ($roomId === '' || $username === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $roomId)) {
        return false;
    }
    $privateFile = __DIR__ . '/private.json';
    if (!file_exists($privateFile)) {
        return false;
    }
    $private = json_decode((string) file_get_contents($privateFile), true);
    if (!is_array($private) || !isset($private['rooms'][$roomId]['users']) || !is_array($private['rooms'][$roomId]['users'])) {
        return false;
    }
    foreach ($private['rooms'][$roomId]['users'] as $u) {
        if (strtolower((string) $u) === strtolower($username)) {
            return true;
        }
    }
    return false;
}

/** Attach manifest details to a message that carries a file_token. */
function attach_upload_info(array $msg): array
{
    if (!isset($msg['file_token']) || $msg['file_token'] === '') {
        return $msg;
    }
    $uploadsDir = __DIR__ . '/../uploads';
    $manifestFile = "$uploadsDir/manifest.json";
    if (file_exists($manifestFile)) {
        $manifest = json_decode(file_get_contents($manifestFile), true) ?? [];
        if (isset($manifest[$msg['file_token']])) {
            $msg['file'] = $manifest[$msg['file_token']];
            $msg['file']['token'] = $msg['file_token'];
        }
    }
    return $msg;
}

/**
 * Return usernames typing in $key (e.g. "public:general" or "private:room_id")
 * within the last ~3.5 seconds, excluding $excludeUser.
 */
function load_typing(string $key, string $excludeUser = ''): array
{
    $file = __DIR__ . '/typing.json';
    if (!file_exists($file)) {
        return [];
    }
    $store = json_decode(file_get_contents($file), true) ?? [];
    $users = $store[$key] ?? [];
    if (!is_array($users)) {
        return [];
    }
    $now = microtime(true);
    $out = [];
    foreach ($users as $u => $ts) {
        if ((float) $ts > $now - 3.5 && strtolower((string) $u) !== strtolower((string) $excludeUser)) {
            $out[] = $u;
        }
    }
    return $out;
}

/** Write a typing entry. */
function store_typing(string $key, string $username, float $ts): void
{
    $file = __DIR__ . '/typing.json';
    $store = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    if (!isset($store[$key]) || !is_array($store[$key])) {
        $store[$key] = [];
    }
    $store[$key][$username] = $ts;
    // Opportunistic cleanup of stale entries to keep the file small.
    foreach ($store as $k => $map) {
        if (!is_array($map)) {
            unset($store[$k]);
            continue;
        }
        foreach ($map as $u => $t) {
            if ((float) $t <= microtime(true) - 8) {
                unset($map[$u]);
            }
        }
        if (empty($map)) {
            unset($store[$k]);
        } else {
            $store[$k] = $map;
        }
    }
    file_put_contents($file, json_encode($store));
}

/** Attach the quoted parent to a public-room message. */
function attach_reply_info($pdo, array $msg): array
{
    $replyId = trim((string) ($msg['reply_to'] ?? ''));
    if ($replyId === '') {
        return $msg;
    }
    $stmt = $pdo->prepare('SELECT id, username, message, deleted FROM messages WHERE id = ? AND room_id = ? LIMIT 1');
    $stmt->execute([$replyId, $msg['room_id'] ?? '']);
    $parent = $stmt->fetch();
    if ($parent) {
        $text = (string) ($parent['message'] ?? '');
        $msg['reply'] = [
            'id' => $parent['id'],
            'username' => $parent['username'],
            'message' => mb_substr($text, 0, 160),
            'deleted' => (int) ($parent['deleted'] ?? 0),
        ];
    }
    return $msg;
}

/** Attach the quoted parent to a private message from the room file. */
function attach_private_reply(array $msg, array $all): array
{
    $replyId = trim((string) ($msg['reply_to'] ?? ''));
    if ($replyId === '') {
        return $msg;
    }
    foreach ($all as $parent) {
        if ((string) ($parent['id'] ?? '') === $replyId) {
            $text = (string) ($parent['message'] ?? '');
            $msg['reply'] = [
                'id' => $parent['id'],
                'username' => $parent['username'] ?? '',
                'message' => mb_substr($text, 0, 160),
                'deleted' => (int) ($parent['deleted'] ?? 0),
            ];
            break;
        }
    }
    return $msg;
}

/** Pinned messages for a public room, newest pin first. */
function room_pins_for($pdo, string $roomId): array
{
    $stmt = $pdo->prepare("SELECT p.message_id, p.pinned_by, p.created_at, m.username, m.message, m.timestamp, m.deleted
        FROM room_pins p JOIN messages m ON m.id = p.message_id
        WHERE p.room_id = ? ORDER BY p.created_at DESC LIMIT 10");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

/** Room creator, admins, and moderators may pin. */
function room_can_pin($pdo, string $roomId, string $username): bool
{
    $stmt = $pdo->prepare('SELECT created_by FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        return false;
    }
    if (strtolower((string) $room['created_by']) === strtolower($username)) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $role = (string) ($stmt->fetch()['role'] ?? 'user');
    return $role === 'admin' || $role === 'moderator';
}

/** Normalize a public message row for the API response. */
function massage_public_message(array $m): array
{
    $m['edited'] = (int) ($m['edited'] ?? 0);
    $m['deleted'] = (int) ($m['deleted'] ?? 0);
    $rx = json_decode((string) ($m['reactions'] ?? '{}'), true);
    $m['reactions'] = is_array($rx) ? $rx : [];
    return attach_upload_info($m);
}