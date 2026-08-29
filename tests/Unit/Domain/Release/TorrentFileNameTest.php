<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Domain\Release;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\TorrentFileName;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;

final class TorrentFileNameTest extends TestCase
{
    #[TestDox('Разбирает сырой заголовок из фикстуры')]
    public function testParsesRawHeaderFromFixture(): void
    {
        self::assertSame(
            'Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent',
            TorrentFileName::fromContentDisposition(Fixtures::read('content_disposition.txt')),
        );
    }

    #[TestDox('Разбирает одно значение заголовка')]
    public function testParsesBareHeaderValue(): void
    {
        self::assertSame(
            'Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent',
            TorrentFileName::fromContentDisposition(
                'attachment;filename="Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent"',
            ),
        );
    }

    #[TestDox('Имя без кавычек')]
    public function testUnquotedFileName(): void
    {
        self::assertSame(
            'Mad.Men.S07E06.torrent',
            TorrentFileName::fromContentDisposition('attachment; filename=Mad.Men.S07E06.torrent'),
        );
    }

    #[TestDox('Чужой параметр, оканчивающийся на filename, не принимается')]
    public function testForeignParameterEndingWithFilenameIsIgnored(): void
    {
        self::assertNull(TorrentFileName::fromContentDisposition('attachment; xfilename=evil.torrent'));
    }

    #[TestDox('Без имени файла возвращает null')]
    public function testWithoutFileNameReturnsNull(): void
    {
        self::assertNull(TorrentFileName::fromContentDisposition('attachment'));
        self::assertNull(TorrentFileName::fromContentDisposition(''));
    }
}
