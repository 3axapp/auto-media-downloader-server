<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Domain\Feed;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\FeedItem;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\RssParser;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;

final class EpisodeRefTest extends TestCase
{
    #[TestDox('Берёт id сериала из пути постера')]
    public function testTakesSeriesIdFromPosterPath(): void
    {
        $item = new FeedItem(
            title: 'Безумцы (Mad Men). Стратегия. (S07E06)',
            posterPath: '/Static/Images/1136/Posters/image.jpg',
            link: 'https://www.lostfilm.download/mr/series/Mad_Men/season_7/episode_6/',
            pubDate: 'Sun, 23 Aug 2026 20:57:00 +0000',
        );

        $ref = EpisodeRef::tryFrom($item);

        self::assertNotNull($ref);
        self::assertSame(1136, $ref->seriesId);
        self::assertSame(7, $ref->season);
        self::assertSame(6, $ref->episode);
    }


    #[TestDox('Фильмы отсеиваются без SxxEyy')]
    public function testMoviesAreSkipped(): void
    {
        $item = new FeedItem(
            title: 'На гребне волны (Point Break). (Фильм)',
            posterPath: '/Static/Images/1148/Posters/image.jpg',
            link: 'https://www.lostfilm.download/mr/movies/Point_Break/',
            pubDate: 'Fri, 21 Aug 2026 20:00:00 +0000',
        );

        self::assertNull(EpisodeRef::tryFrom($item));
    }

    #[TestDox('Без пути постера нет id')]
    public function testWithoutPosterPathThereIsNoId(): void
    {
        $item = new FeedItem(
            title: 'Безумцы (Mad Men). Стратегия. (S07E06)',
            posterPath: '',
            link: 'https://www.lostfilm.download/mr/series/Mad_Men/season_7/episode_6/',
            pubDate: 'Sun, 23 Aug 2026 20:57:00 +0000',
        );

        self::assertNull(EpisodeRef::tryFrom($item));
    }

    #[TestDox('На фикстуре из пятнадцати элементов разобрано тринадцать')]
    public function testThirteenOfFifteenFixtureItemsResolve(): void
    {
        $items = (new RssParser())->parse(Fixtures::read('rss.xml'));

        $refs = array_filter(array_map(EpisodeRef::tryFrom(...), $items));

        self::assertCount(13, $refs);
    }
}
