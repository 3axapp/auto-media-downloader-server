<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage;

use PDO;
use Throwable;

/**
 * PDO синхронен и работает внутри event loop - это осознанное решение:
 * пропускная для одного пользователя за глаза, запросы к локальному SQLite
 * измеряются микросекундами.
 */
final readonly class SqliteConnection
{
    public static function open(string $path): self
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $pdo = new PDO("sqlite:{$path}", options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new self($pdo);
    }

    public static function memory(): self
    {
        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return new self($pdo);
    }

    private function __construct(private PDO $pdo) {}

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS series (
                    id          INTEGER PRIMARY KEY,
                    name_ru     TEXT    NOT NULL,
                    name_en     TEXT    NOT NULL,
                    active      INTEGER NOT NULL DEFAULT 1,
                    poster_path TEXT    NOT NULL DEFAULT '',
                    updated_at  TEXT    NOT NULL
                );
                
                CREATE TABLE IF NOT EXISTS episodes (
                    series_id    INTEGER NOT NULL,
                    season       INTEGER NOT NULL,
                    episode      INTEGER NOT NULL,
                    quality      TEXT    NOT NULL,
                    title        TEXT    NOT NULL DEFAULT '',
                    pub_date     TEXT    NOT NULL DEFAULT '',
                    torrent_name TEXT    NOT NULL DEFAULT '',
                    created_at   TEXT    NOT NULL,
                    PRIMARY KEY (series_id, season, episode, quality)
                );
                
                CREATE TABLE IF NOT EXISTS jobs (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    series_id    INTEGER NOT NULL,
                    season       INTEGER NOT NULL,
                    episode      INTEGER NOT NULL,
                    quality      TEXT    NOT NULL,
                    torrent_name TEXT    NOT NULL,
                    torrent_blob BLOB,
                    status       TEXT    NOT NULL DEFAULT 'pending',
                    attempts     INTEGER NOT NULL DEFAULT 0,
                    lease_until  INTEGER,
                    created_at   TEXT    NOT NULL
                );
                
                CREATE INDEX IF NOT EXISTS jobs_status_lease_idx ON jobs (status, lease_until);
                
                CREATE TABLE IF NOT EXISTS bot_state (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
            SQL,
        );
    }

    /**
     * @param callable(self): mixed $fn
     * @return mixed
     * @throws Throwable
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
