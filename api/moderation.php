<?php

function scanMessage(string $message, string $username, string $roomId, string $type = 'public'): array
{
    $flags = [];

    // Simple keyword check
    $badWords = [];
    foreach ($badWords as $word) {
        if (stripos($message, $word) !== false) {
            $flags[] = ['category' => 'banned_word', 'reason' => "Contains prohibited word"];
            break;
        }
    }

    // Store flags
    if (!empty($flags)) {
        try {
            $database = Database::getInstance();
            foreach ($flags as $flag) {
                $stmt = $database->prepare("INSERT INTO mod_flags (message_id, flagger_username, category, reason) VALUES (?, 'system', ?, ?)");
                $stmt->execute([uniqid('flag_', true), $flag['category'], $flag['reason']]);
            }
        } catch (Throwable $e) {}
    }

    return $flags;
}
