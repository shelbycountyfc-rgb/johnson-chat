<?php

/**
 * Idempotent schema migration. Runs on every database connection so both
 * fresh installs and already-initialized installs get the new columns/tables
 * without the user having to rerun the setup wizard.
 *
 * New columns:
 *   messages.edited        - 1 when the message text was edited
 *   messages.edit_ts       - cursor timestamp so edits/reactions/deletes propagate
 *   messages.deleted       - 1 for tombstoned (deleted) messages
 *   messages.reactions     - JSON object: emoji => [ usernames ]
 *   messages.file_token    - upload token (public rooms previously lost the link)
 *   rooms.read_only        - 1 for admin-only announcement rooms
 *
 * New tables:
 *   blocks                 - BLOCKED: a user who has blocked another
 *   read_state             - per-user, per-room last-read cursor
 */
function ensure_app_schema(PDO $pdo): void
{
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        // Bootstrap a virgin database so authenticated endpoints fail cleanly
        // with 401/403 instead of 500 (no such table). Setup keeps the same DDL.
        if (!in_array('users', $tables, true)) {
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
        }
        if (!in_array('tokens', $tables, true)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
                token TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                expires_at TEXT DEFAULT NULL
            )");
        }
        if (!in_array('rooms', $tables, true)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                created_by TEXT DEFAULT 'system',
                created_at TEXT DEFAULT (datetime('now')),
                last_activity TEXT DEFAULT (datetime('now'))
            )");
        }
        if (!in_array('messages', $tables, true)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                message TEXT NOT NULL,
                room_id TEXT NOT NULL,
                time TEXT DEFAULT '',
                timestamp REAL DEFAULT 0,
                edited INTEGER DEFAULT 0,
                edit_ts REAL DEFAULT 0,
                deleted INTEGER DEFAULT 0,
                reactions TEXT DEFAULT '{}',
                file_token TEXT DEFAULT '',
                reply_to TEXT DEFAULT ''
            )");
        }

        // Live table list may have changed above.
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('users', $tables, true)) {
            return;
        }

        // tokens.expires_at: session TTL. Backfill legacy rows so every token
        // expires 7 days after it was created.
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(tokens)') as $c) {
            $cols[$c['name']] = true;
        }
        if (!isset($cols['expires_at'])) {
            $pdo->exec('ALTER TABLE tokens ADD COLUMN expires_at TEXT DEFAULT NULL');
        }
        $pdo->exec("UPDATE tokens SET expires_at = datetime(created_at, '+7 days') WHERE expires_at IS NULL OR expires_at = ''");

        // messages columns
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(messages)') as $c) {
            $cols[$c['name']] = true;
        }
        $msgUpgrades = [
            'edited' => "ALTER TABLE messages ADD COLUMN edited INTEGER DEFAULT 0",
            'edit_ts' => "ALTER TABLE messages ADD COLUMN edit_ts REAL DEFAULT 0",
            'deleted' => "ALTER TABLE messages ADD COLUMN deleted INTEGER DEFAULT 0",
            'reactions' => "ALTER TABLE messages ADD COLUMN reactions TEXT DEFAULT '{}'",
            'file_token' => "ALTER TABLE messages ADD COLUMN file_token TEXT DEFAULT ''",
            'reply_to' => "ALTER TABLE messages ADD COLUMN reply_to TEXT DEFAULT ''",
        ];
        foreach ($msgUpgrades as $name => $sql) {
            if (!isset($cols[$name])) {
                $pdo->exec($sql);
            }
        }

        // rooms columns
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(rooms)') as $c) {
            $cols[$c['name']] = true;
        }
        if (!isset($cols['read_only'])) {
            $pdo->exec('ALTER TABLE rooms ADD COLUMN read_only INTEGER DEFAULT 0');
        }

        // New tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS blocks (
            username TEXT NOT NULL,
            blocked TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            PRIMARY KEY(username, blocked)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS read_state (
            username TEXT NOT NULL,
            room_id TEXT NOT NULL,
            last_read REAL DEFAULT 0,
            PRIMARY KEY(username, room_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS room_pins (
            room_id TEXT NOT NULL,
            message_id TEXT NOT NULL,
            pinned_by TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            PRIMARY KEY(room_id, message_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(username, endpoint)
        )");
    } catch (Throwable $e) {
        // Never let schema failure break a request; endpoints handle missing
        // columns defensively.
    }
}