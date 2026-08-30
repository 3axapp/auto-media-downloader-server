<?php

declare(strict_types=1);

namespace Integration\Watcher;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Application\Watcher\ExpireStaleJobs;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeNotifier;

final class ExpireStaleJobsTest extends TestCase
{
    #[TestDox('Задание, которое не забрали, помечается один раз и даёт одно сообщение')]
    public function testUnclaimedJobIsMarkedOnceAndNotifiedOnce(): void
    {
        $db = SqliteConnection::memory();
        $db->migrate();

        $clock = new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00'));
        (new SeriesRepository($db, $clock))->upsert(1136, 'Безумцы', 'Mad Men', '');
        $jobs = new JobRepository($db, $clock);
        $id = $jobs->enqueue(new EpisodeRef(1136, 7, 6), QualityLabel::Mp4, 'Mad.Men.S07E06.torrent', 'd…e');

        for ($i = 0; $i < 3; $i++) {
            $jobs->lease(10, 600);
            $clock->advance(601);
        }

        $notifier = new FakeNotifier();
        $expire = new ExpireStaleJobs($jobs, $notifier, new Logger(fopen('php://memory', 'r+')), 3);

        $expire->run();
        $expire->run();

        self::assertSame(JobStatus::Failed, $jobs->find($id)?->status);
        self::assertCount(1, $notifier->sent);
        self::assertStringContainsString('Безумцы', $notifier->sent[0]['text']);
    }
}
