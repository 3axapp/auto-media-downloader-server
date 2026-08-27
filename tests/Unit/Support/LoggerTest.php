<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Support;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;

final class LoggerTest extends TestCase
{
    #[TestDox('Пишет одной строкой с меткой времени и контекстом')]
    public function testWritesSingleLineWithTimestampAndContext(): void
    {
        $stream = fopen('php://memory', 'r+');
        $logger = new Logger(
            $stream,
            new FakeClock(
                new DateTimeImmutable('2026-08-24T18:40:00+00:00'),
            ),
        );

        $logger->info('эпизод поставлен в очередь', ['series' => 1136, 'season' => 7, 'episode' => 6]);

        rewind($stream);
        self::assertSame(
            "2026-08-24T18:40:00+00:00 INFO эпизод поставлен в очередь series=1136 season=7 episode=6\n",
            stream_get_contents($stream),
        );
    }

    #[TestDox('Уровень ошибки и пустой контекст')]
    public function testErrorLevelWithEmptyContext(): void
    {
        $stream = fopen('php://memory', 'r+');
        $logger = new Logger($stream, new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00')));

        $logger->error('сессия протухла');

        rewind($stream);
        self::assertSame("2026-08-24T18:40:00+00:00 ERROR сессия протухла\n", stream_get_contents($stream));
    }
}
