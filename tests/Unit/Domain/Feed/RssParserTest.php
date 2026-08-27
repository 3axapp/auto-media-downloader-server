<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Domain\Feed;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\RssParser;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;

final class RssParserTest extends TestCase
{
    #[TestDox('Разбирает все пятнадцать элементов ленты')]
    public function testParsesAllFifteenFeedItems(): void
    {
        $items = (new RssParser())->parse(Fixtures::read('rss.xml'));

        self::assertCount(15, $items);
    }

    #[TestDox('Первый элемент разобран целиком')]
    public function testFirstItemIsFullyParsed(): void
    {
        $items = (new RssParser())->parse(Fixtures::read('rss.xml'));
        $first = $items[0];

        self::assertSame('Безумцы (Mad Men). Стратегия. (S07E06)', $first->title);
        self::assertSame('/Static/Images/1136/Posters/image.jpg', $first->posterPath);
        self::assertSame('https://www.lostfilm.download/mr/series/Mad_Men/season_7/episode_6/', $first->link);
        self::assertSame('Sun, 23 Aug 2026 20:57:00 +0000', $first->pubDate);
    }
}
