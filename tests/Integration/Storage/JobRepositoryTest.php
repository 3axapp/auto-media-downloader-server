<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;

final class JobRepositoryTest extends TestCase
{
    private FakeClock $clock;
    private SqliteConnection $db;
    private JobRepository $jobs;

    protected function setUp(): void
    {
        $this->db = SqliteConnection::memory();
        $this->db->migrate();
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00'));
        $this->jobs = new JobRepository($this->db, $this->clock);

        (new SeriesRepository($this->db, $this->clock))->upsert(1136, 'Безумцы', 'Mad Men', '');
    }

    private function enqueue(string $blob = "d8:announce…e"): int
    {
        return $this->jobs->enqueue(
            new EpisodeRef(1136, 7, 6),
            QualityLabel::Mp4,
            'Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent',
            $blob,
        );
    }

    private function enqueueFor(int $seriesId, int $episode): int
    {
        return $this->jobs->enqueue(
            new EpisodeRef($seriesId, 7, $episode),
            QualityLabel::Mp4,
            'S07E' . $episode . '.torrent',
            "d8:announce…e",
        );
    }

    #[TestDox('Выдача переводит в leased и наращивает попытки')]
    public function testLeaseMarksLeasedAndIncrementsAttempts(): void
    {
        $id = $this->enqueue();

        $leased = $this->jobs->lease(10, 600);

        self::assertCount(1, $leased);
        self::assertSame($id, $leased[0]->id);
        self::assertSame(JobStatus::Leased, $leased[0]->status);
        self::assertSame(1, $leased[0]->attempts);
        self::assertSame($this->clock->timestamp() + 600, $leased[0]->leaseUntil);
        self::assertSame('Безумцы', $leased[0]->seriesName);
        self::assertSame('Mad Men', $leased[0]->seriesNameEn);
    }

    #[TestDox('Залиженное задание не выдаётся повторно до истечения срока')]
    public function testLeasedJobIsNotHandedOutBeforeExpiry(): void
    {
        $this->enqueue();
        $this->jobs->lease(10, 600);

        $this->clock->advance(599);

        self::assertSame([], $this->jobs->lease(10, 600));
    }

    #[TestDox('Просроченный лизинг переотдаётся')]
    public function testExpiredLeaseIsHandedOutAgain(): void
    {
        $id = $this->enqueue();
        $this->jobs->lease(10, 600);

        $this->clock->advance(601);
        $again = $this->jobs->lease(10, 600);

        self::assertCount(1, $again);
        self::assertSame($id, $again[0]->id);
        self::assertSame(2, $again[0]->attempts);
    }

    #[TestDox('Байты торрента отдаются побайтово и обнуляются после ack')]
    public function testTorrentBytesSurviveRoundTripAndClearOnAck(): void
    {
        $blob = "d8:announce20:http://example/annce4:infod4:name9:dummy.mp4e\x00\xff";
        $id = $this->enqueue($blob);

        self::assertSame($blob, $this->jobs->torrentBlob($id));
        self::assertTrue($this->jobs->ack($id));
        self::assertNull($this->jobs->torrentBlob($id));
        self::assertSame(JobStatus::Acked, $this->jobs->find($id)?->status);
    }

    #[TestDox('Забранное задание больше не выдаётся')]
    public function testAckedJobIsNeverHandedOutAgain(): void
    {
        $id = $this->enqueue();
        $this->jobs->lease(10, 600);
        $this->jobs->ack($id);

        $this->clock->advance(10_000);

        self::assertSame([], $this->jobs->lease(10, 600));
    }

    #[TestDox('Лимит выдачи соблюдается')]
    public function testLeaseRespectsLimit(): void
    {
        $this->enqueue();
        $this->enqueue();
        $this->enqueue();

        self::assertCount(2, $this->jobs->lease(2, 600));
    }

    #[TestDox('Завершение с ошибкой обнуляет блоб и возвращает задание')]
    public function testFailedCompletionClearsBlobAndReturnsJob(): void
    {
        $id = $this->enqueue();
        $this->jobs->lease(10, 600);

        $job = $this->jobs->complete($id, JobStatus::Failed);

        self::assertNotNull($job);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertNull($this->jobs->torrentBlob($id));
        self::assertSame([], $this->jobs->lease(10, 600));
    }

    #[TestDox('Завершение несуществующего задания даёт null')]
    public function testCompletingUnknownJobYieldsNull(): void
    {
        self::assertNull($this->jobs->complete(999, JobStatus::Done));
    }

    #[TestDox('Задание с живым лизингом не проваливается досрочно')]
    public function testActiveLeaseIsNotExpired(): void
    {
        $id = $this->enqueue();

        for ($i = 0; $i < 5; $i++) {
            $this->jobs->lease(10, 600);
            $this->clock->advance(601);
        }

        // Клиент забрал задание только что - лизинг ещё жив, он качает.
        $this->jobs->lease(10, 600);
        $this->clock->advance(100);

        self::assertSame([], $this->jobs->expire(5));
        self::assertSame(JobStatus::Leased, $this->jobs->find($id)?->status);

        // Лизинг истёк - задание действительно брошено.
        $this->clock->advance(501);

        self::assertCount(1, $this->jobs->expire(5));
    }

    #[TestDox('Исключение внутри транзакции откатывает вставку и пробрасывается дальше')]
    public function testTransactionRollsBackAndRethrows(): void
    {
        $caught = null;

        try {
            $this->db->transaction(function (): void {
                $this->enqueue();

                throw new RuntimeException('сбой внутри транзакции');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'исключение должно быть проброшено дальше');
        self::assertSame('сбой внутри транзакции', $caught->getMessage());
        // Откат состоялся: задание не попало в очередь.
        self::assertSame([], $this->jobs->lease(10, 600));
    }

    #[TestDox('Превышение числа попыток переводит в failed')]
    public function testExceedingMaxAttemptsMarksFailed(): void
    {
        $id = $this->enqueue();

        for ($i = 0; $i < 5; $i++) {
            $this->jobs->lease(10, 600);
            $this->clock->advance(601);
        }

        $expired = $this->jobs->expire(5);

        self::assertCount(1, $expired);
        self::assertSame($id, $expired[0]->id);
        self::assertSame(JobStatus::Failed, $this->jobs->find($id)?->status);
        self::assertNull($this->jobs->torrentBlob($id));

        // Второй вызов не должен сообщать о том же задании повторно.
        self::assertSame([], $this->jobs->expire(5));
    }

    #[TestDox('Удаление по сериалу снимает незавершённые задания только этого сериала')]
    public function testDeleteBySeriesRemovesUnfinishedJobsOfThatSeriesOnly(): void
    {
        $leased = $this->enqueueFor(1136, 2);
        $acked = $this->enqueueFor(1136, 3);

        $this->jobs->lease(10, 600);
        $this->jobs->ack($acked);

        // После лизинга — иначе `pending` в тесте был бы на деле `leased`.
        $pending = $this->enqueueFor(1136, 1);
        $stranger = $this->enqueueFor(733, 1);

        self::assertSame(JobStatus::Pending, $this->jobs->find($pending)?->status);
        self::assertSame(JobStatus::Leased, $this->jobs->find($leased)?->status);
        self::assertSame(JobStatus::Acked, $this->jobs->find($acked)?->status);

        self::assertSame(3, $this->jobs->deleteBySeries(1136));

        self::assertNull($this->jobs->find($pending));
        self::assertNull($this->jobs->find($leased));
        self::assertNull($this->jobs->find($acked));
        self::assertNotNull($this->jobs->find($stranger));
    }

    #[TestDox('Удаление по сериалу сохраняет журнал done и failed')]
    public function testDeleteBySeriesKeepsFinishedJobs(): void
    {
        $done = $this->enqueueFor(1136, 1);
        $failed = $this->enqueueFor(1136, 2);

        $this->jobs->complete($done, JobStatus::Done);
        $this->jobs->complete($failed, JobStatus::Failed);

        self::assertSame(0, $this->jobs->deleteBySeries(1136));

        self::assertSame(JobStatus::Done, $this->jobs->find($done)?->status);
        self::assertSame(JobStatus::Failed, $this->jobs->find($failed)?->status);
    }
}
