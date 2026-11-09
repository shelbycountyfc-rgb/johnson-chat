<?php

require_once __DIR__ . '/database.php';

/**
 * Simple sliding-window rate limiter backed by SQLite.
 *
 * Each bucket counts events within a fixed window. Window rolls over
 * automatically; stale buckets are replaced with a fresh count.
 */

function rate_limit_is_allowed(string $bucket, int $maxHits, int $windowSec): bool
{
    try {
        $db = Database::getInstance();
        $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, count INTEGER DEFAULT 0, window_start INTEGER DEFAULT 0)');
        $win = (int) intdiv(time(), max(1, $windowSec));
        $stmt = $db->prepare('SELECT count, window_start FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['window_start'] !== $win) {
            return true;
        }
        return (int) $row['count'] < $maxHits;
    } catch (Throwable $e) {
        // Fail open if the database is temporarily unavailable so the app stays usable.
        return true;
    }
}

function rate_limit_register(string $bucket, int $windowSec): void
{
    try {
        $db = Database::getInstance();
        $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, count INTEGER DEFAULT 0, window_start INTEGER DEFAULT 0)');
        $win = (int) intdiv(time(), max(1, $windowSec));
        $stmt = $db->prepare('SELECT count, window_start FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['window_start'] !== $win) {
            $stmt = $db->prepare('INSERT OR REPLACE INTO rate_limits (bucket, count, window_start) VALUES (?, 1, ?)');
            $stmt->execute([$bucket, $win]);
        } else {
            $stmt = $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE bucket = ?');
            $stmt->execute([$bucket]);
        }
    } catch (Throwable $e) {}
}

function rate_limit_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (isset($GLOBALS['APP_TRUST_XFF']) && $GLOBALS['APP_TRUST_XFF']) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            $ip = $parts[0] !== '' ? $parts[0] : $ip;
        }
    }
    return $ip;
}