<?php

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    private function __construct()
    {
        $dbDir = __DIR__ . '/data';
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }
        $path = $dbDir . '/chat.db';
        $this->pdo = new PDO("sqlite:$path", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        require_once __DIR__ . '/schema.php';
        ensure_app_schema($this->pdo);
        app_prune_tokens($this->pdo);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function exec(string $sql): int
    {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function close(): void
    {
        $this->pdo = null;
        self::$instance = null;
    }
}

function app_token_sql(): string
{
    return "SELECT username FROM tokens WHERE token = ? AND (expires_at IS NOT NULL AND expires_at <> '' AND expires_at > datetime('now'))";
}

function app_session_check(Database $db, string $token): ?array
{
    $stmt = $db->prepare(app_token_sql());
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $db->prepare("UPDATE tokens SET expires_at = datetime('now', '+7 days') WHERE token = ? AND expires_at <= datetime('now', '+1 day')")->execute([$token]);
    return $row;
}

function app_prune_tokens(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM tokens WHERE (expires_at IS NOT NULL AND expires_at <> '' AND expires_at <= datetime('now')) OR ((expires_at IS NULL OR expires_at = '') AND created_at <= datetime('now', '-7 days'))");
    } catch (Throwable $e) {}
}
