<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage;

use Zakharov\AutoMediaDownloaderServer\Domain\Series\Series;
use Zakharov\AutoMediaDownloaderServer\Support\Clock;

final readonly class SeriesRepository
{
    public function __construct(
        private SqliteConnection $db,
        private Clock $clock,
    ) {}

    /**
     * Названия и постер обновляются, флаг `active` остаётся как есть.
     * @param int $id
     * @param string $nameRu
     * @param string $nameEn
     * @param string $posterPath
     * @return void
     */
    public function upsert(int $id, string $nameRu, string $nameEn, string $posterPath): void
    {
        $statement = $this->db->pdo()->prepare(
            <<<'SQL'
                INSERT INTO series (id, name_ru, name_en, poster_path, updated_at)
                VALUES (:id, :name_ru, :name_en, :poster_path, :updated_at)
                ON CONFLICT (id) DO UPDATE SET
                    name_ru     = excluded.name_ru,
                    name_en     = excluded.name_en,
                    poster_path = excluded.poster_path,
                    updated_at  = excluded.updated_at
            SQL,
        );

        $statement->execute([
            ':id'          => $id,
            ':name_ru'     => $nameRu,
            ':name_en'     => $nameEn,
            ':poster_path' => $posterPath,
            ':updated_at'  => $this->clock->now()->format('c'),
        ]);
    }

    public function posterPath(int $id): ?string
    {
        $statement = $this->db->pdo()->prepare('SELECT poster_path FROM series WHERE id = :id');
        $statement->execute([':id' => $id]);
        $path = $statement->fetchColumn();

        return ($path === false || $path === '') ? null : (string)$path;
    }

    public function isActive(int $id): bool
    {
        $statement = $this->db->pdo()->prepare('SELECT active FROM series WHERE id = :id');
        $statement->execute([':id' => $id]);
        $active = $statement->fetchColumn();

        return $active !== false && (int)$active === 1;
    }

    public function setActive(int $id, bool $active): bool
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE series SET active = :active, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            ':active'     => $active ? 1 : 0,
            ':updated_at' => $this->clock->now()->format('c'),
            ':id'         => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<Series>
     */
    public function all(): array
    {
        $rows = $this->db
            ->pdo()
            ->query('SELECT id, name_ru, name_en, active FROM series ORDER BY name_ru')
            ->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function find(int $id): ?Series
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, name_ru, name_en, active FROM series WHERE id = :id',
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Перебор в PHP, а не LIKE: `lower()` в SQLite работает только с ASCII,
     * поэтому кириллица искалась бы регистрозависимо. Список сериалов - десятки строк.
     */
    public function findByName(string $needle): ?Series
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return null;
        }

        $rows = $this->db
            ->pdo()
            ->query('SELECT id, name_ru, name_en, active FROM series ORDER BY length(name_ru)')
            ->fetchAll();

        foreach ($rows as $row) {
            if (str_contains(mb_strtolower((string)$row['name_ru']), $needle)
                || str_contains(mb_strtolower((string)$row['name_en']), $needle)
            ) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return Series
     */
    private function hydrate(array $row): Series
    {
        return new Series(
            id: (int)$row['id'],
            nameRu: (string)$row['name_ru'],
            nameEn: (string)$row['name_en'],
            active: (int)$row['active'] === 1,
        );
    }
}
