<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage;

final readonly class BotStateRepository
{
    private const string OFFSET_KEY = 'telegram_offset';

    public function __construct(private SqliteConnection $db) {}

    public function get(string $key): ?string
    {
        $statement = $this->db->pdo()->prepare('SELECT value FROM bot_state WHERE key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->db->pdo()->prepare(
            <<<'SQL'
                INSERT INTO bot_state (key, value) VALUES (:key, :value)
                ON CONFLICT (key) DO UPDATE SET value = excluded.value
            SQL,
        );
        $statement->execute([':key' => $key, ':value' => $value]);
    }

    public function offset(): int
    {
        return (int)($this->get(self::OFFSET_KEY) ?? '0');
    }

    public function setOffset(int $offset): void
    {
        $this->set(self::OFFSET_KEY, (string)$offset);
    }
}
