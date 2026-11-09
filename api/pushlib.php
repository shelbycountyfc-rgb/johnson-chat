<?php
require_once __DIR__ . '/webpush.php';

/** Send a payload to every push subscription for one user. */
function push_notify_user(string $username, array $payload): int
{
    $database = Database::getInstance();
    $stmt = $database->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE LOWER(username) = LOWER(?)');
    $stmt->execute([$username]);
    $rows = $stmt->fetchAll();
    $sent = 0;
    foreach ($rows as $row) {
        $result = wp_send_subscription([
            'endpoint' => $row['endpoint'],
            'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
        ], $payload);
        if ($result['ok']) {
            $sent++;
        } elseif (in_array((int) $result['status'], [404, 410], true)) {
            $database->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$row['id']]);
        }
    }
    $database->close();
    return $sent;
}

/** Push only to @mentioned users in a public-room message. */
function push_notify_mentions(string $roomId, string $text, string $sender): void
{
    if (!preg_match_all('/(^|[^\w])@([a-zA-Z0-9_]{1,20})/', $text, $matches)) {
        return;
    }
    $names = array_values(array_unique(array_map('strtolower', $matches[2])));
    $database = Database::getInstance();
    $targets = [];
    foreach ($names as $name) {
        if ($name === strtolower($sender)) {
            continue;
        }
        $stmt = $database->prepare('SELECT username FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $stmt->execute([$name]);
        $target = $stmt->fetch();
        if ($target) {
            $targets[] = $target['username'];
        }
    }
    $database->close();
    foreach ($targets as $target) {
        push_notify_user($target, [
            'title' => 'JohnsonChat mention',
            'body' => $sender . ' mentioned you in #' . $roomId,
            'url' => './messnger.html?room=' . rawurlencode($roomId),
            'tag' => 'mention-' . $roomId,
        ]);
    }
}
