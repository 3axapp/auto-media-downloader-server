<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage;

use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Support\Clock;

/**
 * Журнал дедупликации. Ключ - (series_id, season, episode, quality).
 */
final readonly class EpisodeRepository
{
    public function __construct(
        private SqliteConnection $db,
        private Clock $clock,
    ) {}

    public function has(EpisodeRef $ref, QualityLabel $quality): bool
    {
        $statement = $this->db->pdo()->prepare(
            <<<'SQL'
                SELECT 1 
                FROM episodes
                WHERE 
                    series_id = :series_id AND 
                    season = :season AND 
                    episode = :episode AND 
                    quality = :quality
            SQL,
        );
        $statement->execute([
            ':series_id' => $ref->seriesId,
            ':season'    => $ref->season,
            ':episode'   => $ref->episode,
            ':quality'   => $quality->value,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function insert(
        EpisodeRef $ref,
        QualityLabel $quality,
        string $title,
        string $pubDate,
        string $torrentName,
    ): void {
        $statement = $this->db->pdo()->prepare(
            <<<'SQL'
                INSERT INTO episodes (series_id, season, episode, quality, title, pub_date, torrent_name, created_at)
                VALUES (:series_id, :season, :episode, :quality, :title, :pub_date, :torrent_name, :created_at)
                ON CONFLICT (series_id, season, episode, quality) DO NOTHING
            SQL,
        );

        $statement->execute([
            ':series_id'    => $ref->seriesId,
            ':season'       => $ref->season,
            ':episode'      => $ref->episode,
            ':quality'      => $quality->value,
            ':title'        => $title,
            ':pub_date'     => $pubDate,
            ':torrent_name' => $torrentName,
            ':created_at'   => $this->clock->now()->format('c'),
        ]);
    }
}
