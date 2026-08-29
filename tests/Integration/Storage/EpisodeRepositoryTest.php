<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\EpisodeRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;

final class EpisodeRepositoryTest extends TestCase
{
    private EpisodeRepository $episodes;

    protected function setUp(): void
    {
        $db = SqliteConnection::memory();
        $db->migrate();
        $this->episodes = new EpisodeRepository($db, new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00')));
    }

    #[TestDox('Записанный эпизод считается обработанным')]
    public function testStoredEpisodeCountsAsProcessed(): void
    {
        $ref = new EpisodeRef(1136, 7, 6);

        self::assertFalse($this->episodes->has($ref, QualityLabel::Mp4));

        $this->episodes->insert($ref, QualityLabel::Mp4, 'Стратегия', 'Sun, 23 Aug 2026 20:57:00 +0000', 'Mad.Men.S07E06.mp4.torrent');

        self::assertTrue($this->episodes->has($ref, QualityLabel::Mp4));
    }

    #[TestDox('Качество входит в ключ дедупликации')]
    public function testQualityIsPartOfDeduplicationKey(): void
    {
        $ref = new EpisodeRef(1136, 7, 6);
        $this->episodes->insert($ref, QualityLabel::Mp4, 'Стратегия', '', 'a.torrent');

        // Смена QUALITY — явное решение пользователя: эпизод должен быть перекачан.
        self::assertFalse($this->episodes->has($ref, QualityLabel::Hd1080));
    }

    #[TestDox('Повторная запись того же ключа не падает')]
    public function testRepeatedInsertOfSameKeyDoesNotFail(): void
    {
        $ref = new EpisodeRef(1136, 7, 6);
        $this->episodes->insert($ref, QualityLabel::Mp4, 'Стратегия', '', 'a.torrent');
        $this->episodes->insert($ref, QualityLabel::Mp4, 'Стратегия', '', 'a.torrent');

        self::assertTrue($this->episodes->has($ref, QualityLabel::Mp4));
    }
}
