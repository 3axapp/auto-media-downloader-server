<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage;

use InvalidArgumentException;
use PDO;
use Throwable;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\Job;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Support\Clock;

final readonly class JobRepository
{
    private const string SELECT = <<<'SQL'
        SELECT j.id, j.series_id, j.season, j.episode, j.quality, j.torrent_name,
            j.status, j.attempts, j.lease_until,
            COALESCE(s.name_ru, '') AS name_ru, COALESCE(s.name_en, '') AS name_en
        FROM jobs j
        LEFT JOIN series s ON s.id = j.series_id
        SQL;

    public function __construct(
        private SqliteConnection $db,
        private Clock $clock,
    ) {}

    public function enqueue(EpisodeRef $ref, QualityLabel $quality, string $torrentName, string $torrentBlob): int
    {
        $statement = $this->db->pdo()->prepare(
            <<<'SQL'
                INSERT INTO jobs (series_id, season, episode, quality, torrent_name, torrent_blob, status, attempts, created_at)
                VALUES (:series_id, :season, :episode, :quality, :torrent_name, :torrent_blob, 'pending', 0, :created_at)
            SQL,
        );

        $statement->bindValue(':series_id', $ref->seriesId, PDO::PARAM_INT);
        $statement->bindValue(':season', $ref->season, PDO::PARAM_INT);
        $statement->bindValue(':episode', $ref->episode, PDO::PARAM_INT);
        $statement->bindValue(':quality', $quality->value);
        $statement->bindValue(':torrent_name', $torrentName);
        $statement->bindValue(':torrent_blob', $torrentBlob, PDO::PARAM_LOB);
        $statement->bindValue(':created_at', $this->clock->now()->format('c'));
        $statement->execute();

        return (int)$this->db->pdo()->lastInsertId();
    }

    /**
     * @param int $limit
     * @param int $ttlSeconds
     * @return list<Job>
     * @throws Throwable
     */
    public function lease(int $limit, int $ttlSeconds): array
    {
        $now = $this->clock->timestamp();

        return $this->db->transaction(function () use ($limit, $ttlSeconds, $now): array {
            $select = $this->db->pdo()->prepare(
                self::SELECT . <<<'SQL'
                     WHERE j.status = 'pending'
                        OR (j.status = 'leased' AND (j.lease_until IS NULL OR j.lease_until <= :now))
                     ORDER BY j.id
                     LIMIT :limit
                SQL,
            );
            $select->bindValue(':now', $now, PDO::PARAM_INT);
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();

            $update = $this->db->pdo()->prepare(
                <<<'SQL'
                    UPDATE jobs
                    SET status = 'leased', lease_until = :lease_until, attempts = attempts + 1
                    WHERE id = :id
                    SQL,
            );

            $jobs = [];

            foreach ($select->fetchAll() as $row) {
                $update->execute([':lease_until' => $now + $ttlSeconds, ':id' => (int)$row['id']]);

                $row['status'] = JobStatus::Leased->value;
                $row['attempts'] = (int)$row['attempts'] + 1;
                $row['lease_until'] = $now + $ttlSeconds;
                $jobs[] = $this->hydrate($row);
            }

            return $jobs;
        });
    }

    public function find(int $id): ?Job
    {
        $statement = $this->db->pdo()->prepare(self::SELECT . ' WHERE j.id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function torrentBlob(int $id): ?string
    {
        $statement = $this->db->pdo()->prepare('SELECT torrent_blob FROM jobs WHERE id = :id');
        $statement->execute([':id' => $id]);
        $blob = $statement->fetchColumn();

        return ($blob === false || $blob === null) ? null : (string)$blob;
    }

    public function ack(int $id): bool
    {
        $statement = $this->db->pdo()->prepare(
            "UPDATE jobs SET status = 'acked', torrent_blob = NULL, lease_until = NULL WHERE id = :id",
        );
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Клиент отчитался о результате: `done` либо `failed`. Байты больше не нужны.
     * @param int $id
     * @param JobStatus $status
     * @return Job|null
     */
    public function complete(int $id, JobStatus $status): ?Job
    {
        if ($status !== JobStatus::Done && $status !== JobStatus::Failed) {
            throw new InvalidArgumentException('complete() принимает только done или failed');
        }

        $statement = $this->db->pdo()->prepare(
            'UPDATE jobs SET status = :status, torrent_blob = NULL, lease_until = NULL WHERE id = :id',
        );
        $statement->execute([':status' => $status->value, ':id' => $id]);

        return $statement->rowCount() > 0 ? $this->find($id) : null;
    }

    /**
     * Сериал сняли со слежения - незавершённая очередь по нему смысла не имеет.
     * `done`/`failed` остаются: это журнал, по нему считает `/status`.
     *
     * Клиент, уже качающий серию по `leased`/`acked`, получит 404 на
     * `/jobs/{id}/torrent` и `/hooks/complete` — остановить скачивание на той
     * стороне мы всё равно не можем, а переотдавать снятое незачем.
     */
    public function deleteBySeries(int $seriesId): int
    {
        $statement = $this->db->pdo()->prepare(
            "DELETE FROM jobs WHERE series_id = :series_id AND status IN ('pending', 'leased', 'acked')",
        );
        $statement->execute([':series_id' => $seriesId]);

        return $statement->rowCount();
    }

    public function countByStatus(JobStatus $status): int
    {
        $statement = $this->db->pdo()->prepare('SELECT count(*) FROM jobs WHERE status = :status');
        $statement->execute([':status' => $status->value]);

        return (int)$statement->fetchColumn();
    }

    /**
     * Задание, которое никто не забрал за MAX_ATTEMPTS выдач: клиент лежит
     * либо ломается на конкретном торренте. Помечаем один раз, чтобы сообщение ушло однократно.
     *
     * Задание с ещё живым лизингом не трогается: клиент мог получить последнюю
     * выдачу только что и добросовестно качать торрент - досрочный `failed`
     * обнулил бы блоб раньше времени и дал бы клиенту 404 на `/jobs/{id}/torrent`.
     *
     * @param int $maxAttempts
     * @return Job[]
     * @throws Throwable
     */
    public function expire(int $maxAttempts): array
    {
        $now = $this->clock->timestamp();

        return $this->db->transaction(function () use ($maxAttempts, $now): array {
            $select = $this->db->pdo()->prepare(
                self::SELECT . <<<'SQL'
                    WHERE (j.status = 'pending'
                           OR (j.status = 'leased' AND (j.lease_until IS NULL OR j.lease_until <= :now)))
                      AND j.attempts >= :max_attempts
                    ORDER BY j.id
                SQL,
            );
            $select->bindValue(':now', $now, PDO::PARAM_INT);
            $select->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
            $select->execute();

            $update = $this->db->pdo()->prepare(
                "UPDATE jobs SET status = 'failed', torrent_blob = NULL, lease_until = NULL WHERE id = :id",
            );

            $jobs = [];

            foreach ($select->fetchAll() as $row) {
                $update->execute([':id' => (int)$row['id']]);
                $row['status'] = JobStatus::Failed->value;
                $jobs[] = $this->hydrate($row);
            }

            return $jobs;
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return Job
     */
    private function hydrate(array $row): Job
    {
        return new Job(
            id: (int)$row['id'],
            seriesId: (int)$row['series_id'],
            seriesName: (string)$row['name_ru'],
            seriesNameEn: (string)$row['name_en'],
            season: (int)$row['season'],
            episode: (int)$row['episode'],
            quality: QualityLabel::from((string)$row['quality']),
            torrentName: (string)$row['torrent_name'],
            status: JobStatus::from((string)$row['status']),
            attempts: (int)$row['attempts'],
            leaseUntil: $row['lease_until'] === null ? null : (int)$row['lease_until'],
        );
    }
}
