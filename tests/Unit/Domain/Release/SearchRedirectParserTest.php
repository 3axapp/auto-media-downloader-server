<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Domain\Release;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\SearchRedirectParser;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;

final class SearchRedirectParserTest extends TestCase
{
    private const EXPECTED = '/V/?c=1136&s=7&e=6&u=1111111&h=00000000000000000000000000000000&n=1&newbie=&br=&ts=1700000000';

    #[TestDox('Берёт путь из meta refresh')]
    public function testTakesPathFromMetaRefresh(): void
    {
        $path = (new SearchRedirectParser())->parse(Fixtures::read('v_search.html'));

        self::assertSame(self::EXPECTED, $path);
    }

    #[TestDox('Путь остаётся относительным')]
    public function testPathStaysRelative(): void
    {
        $path = (new SearchRedirectParser())->parse(Fixtures::read('v_search.html'));

        self::assertNotNull($path);
        self::assertStringStartsWith('/V/?', $path);
    }

    #[TestDox('Откат на location.replace, если meta нет')]
    public function testFallsBackToLocationReplace(): void
    {
        $html = <<<'HTML'
            <html><head><script type="text/javascript">
            function r()
            <!--
            location.replace("/V/?c=1&s=2&e=3&u=7&h=deadbeef&n=1&newbie=&br=&ts=1700000001");
            //-->
            </script></head><body></body></html>
            HTML;

        self::assertSame(
            '/V/?c=1&s=2&e=3&u=7&h=deadbeef&n=1&newbie=&br=&ts=1700000001',
            (new SearchRedirectParser())->parse($html),
        );
    }

    #[TestDox('Без редиректа возвращает null')]
    public function testWithoutRedirectReturnsNull(): void
    {
        self::assertNull((new SearchRedirectParser())->parse('<html><body>Ничего</body></html>'));
    }

    #[TestDox('HTML-сущности раскрываются')]
    public function testHtmlEntitiesAreDecoded(): void
    {
        $html = '<meta http-equiv="refresh" content="0; url=/V/?c=1&amp;s=2&amp;e=3">';

        self::assertSame('/V/?c=1&s=2&e=3', (new SearchRedirectParser())->parse($html));
    }
}
