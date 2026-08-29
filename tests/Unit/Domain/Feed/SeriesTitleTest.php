<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Domain\Feed;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\SeriesTitle;

final class SeriesTitleTest extends TestCase
{
    #[TestDox('Разбирает заголовок эпизода')]
    public function testParsesEpisodeTitle(): void
    {
        $title = SeriesTitle::fromFeedTitle('Безумцы (Mad Men). Стратегия. (S07E06)');

        self::assertNotNull($title);
        self::assertSame('Безумцы', $title->ru);
        self::assertSame('Mad Men', $title->en);
        self::assertSame('Стратегия', $title->episodeTitle);
    }

    #[TestDox('Двоеточие в названии не ломает разбор')]
    public function testColonInNameDoesNotBreakParsing(): void
    {
        $title = SeriesTitle::fromFeedTitle(
            'Библиотекари: Следующая глава (The Librarians: The Next Chapter). И кошмар Декарта. (S02E06)',
        );

        self::assertNotNull($title);
        self::assertSame('Библиотекари: Следующая глава', $title->ru);
        self::assertSame('The Librarians: The Next Chapter', $title->en);
        self::assertSame('И кошмар Декарта', $title->episodeTitle);
    }

    #[TestDox('Апостроф в английском названии')]
    public function testApostropheInEnglishName(): void
    {
        $title = SeriesTitle::fromFeedTitle(
            "В Филадельфии всегда солнечно (It's Always Sunny in Philadelphia). Фрэнк берёт замуж труп. (S18E01)",
        );

        self::assertNotNull($title);
        self::assertSame("It's Always Sunny in Philadelphia", $title->en);
        self::assertSame('Фрэнк берёт замуж труп', $title->episodeTitle);
    }

    #[TestDox('У фильма нет названия эпизода')]
    public function testMovieHasNoEpisodeTitle(): void
    {
        $title = SeriesTitle::fromFeedTitle('На гребне волны (Point Break). (Фильм)');

        self::assertNotNull($title);
        self::assertSame('На гребне волны', $title->ru);
        self::assertSame('Point Break', $title->en);
        self::assertNull($title->episodeTitle);
    }

    #[TestDox('Неразбираемый заголовок даёт null')]
    public function testUnparsableTitleYieldsNull(): void
    {
        self::assertNull(SeriesTitle::fromFeedTitle('Просто строка без скобок'));
    }
}
