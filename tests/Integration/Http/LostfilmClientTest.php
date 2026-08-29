<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\SearchRedirectParser;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\LostfilmClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\SessionExpiredException;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Session\ConfigCookieProvider;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeHttpClient;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\PromiseAssertions;

final class LostfilmClientTest extends TestCase
{
    use PromiseAssertions;

    private FakeHttpClient $http;
    private LostfilmClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->client = new LostfilmClient(
            $this->http,
            new ConfigCookieProvider('session-value', null),
            new SearchRedirectParser(),
        );
    }

    #[TestDox('Лента запрашивается по HTTPS и без cookie')]
    public function testFeedIsFetchedOverHttpsWithoutCookie(): void
    {
        $this->http->on('/rss.xml', new HttpResponse(200, [], Fixtures::read('rss.xml')));

        $body = $this->resolved($this->client->fetchRss());

        self::assertStringContainsString('<rss version="0.91">', $body);
        $request = $this->http->requestsTo('/rss.xml')[0];
        self::assertSame('https://www.lostfilm.tv/rss.xml', $request['url']);
        self::assertArrayNotHasKey('Cookie', $request['headers']);
    }

    #[TestDox('Поиск отдаёт абсолютный URL и получает cookie')]
    public function testSearchReturnsAbsoluteUrlAndSendsCookie(): void
    {
        $this->http->on('/v_search.php', new HttpResponse(200, [], Fixtures::read('v_search.html')));

        $url = $this->resolved($this->client->resolveSearch(new EpisodeRef(1136, 7, 6)));

        // Легаси передавал результат в curl как абсолютный URL — теперь хост подставляем сами.
        self::assertStringStartsWith('https://www.lostfilm.tv/V/?c=1136&s=7&e=6&u=', $url);

        $request = $this->http->requestsTo('/v_search.php')[0];
        self::assertSame('https://www.lostfilm.tv/v_search.php?c=1136&s=7&e=6', $request['url']);
        self::assertSame('lf_session=session-value', $request['headers']['Cookie']);
    }

    #[TestDox('Ответ 302 означает протухшую сессию')]
    public function testRedirectMeansExpiredSession(): void
    {
        $this->http->on('/v_search.php', new HttpResponse(302, ['location' => '/'], ''));

        $error = $this->rejected($this->client->resolveSearch(new EpisodeRef(1136, 7, 6)));

        self::assertInstanceOf(SessionExpiredException::class, $error);
    }

    #[TestDox('Ответ без редиректа отклоняется')]
    public function testResponseWithoutRedirectIsRejected(): void
    {
        $this->http->on('/v_search.php', new HttpResponse(200, [], '<html>пусто</html>'));

        $error = $this->rejected($this->client->resolveSearch(new EpisodeRef(1136, 7, 6)));

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertNotInstanceOf(SessionExpiredException::class, $error);
    }

    #[TestDox('Страница релиза получает cookie')]
    public function testReleasePageReceivesCookie(): void
    {
        $this->http->on('/V/?', new HttpResponse(200, [], Fixtures::read('release_page.html')));

        $html = $this->resolved($this->client->fetchReleasePage('https://www.lostfilm.tv/V/?c=1136&s=7&e=6&ts=1700000000'));

        self::assertStringContainsString('inner-box--item', $html);
        self::assertSame('lf_session=session-value', $this->http->requestsTo('/V/?')[0]['headers']['Cookie']);
    }

    #[TestDox('Cookie не уходит на внешний хост торрента')]
    public function testCookieIsNotSentToTorrentHost(): void
    {
        $this->http->on('n.tracktor.site', new HttpResponse(
            200,
            ['content-disposition' => 'attachment;filename="Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent"'],
            Fixtures::read('sample.torrent'),
        ));

        $torrent = $this->resolved($this->client->fetchTorrent('https://n.tracktor.site/td.php?s=opaque'));

        self::assertSame('Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent', $torrent->name);
        self::assertStringStartsWith('d', $torrent->bytes);

        $request = $this->http->requestsTo('n.tracktor.site')[0];
        self::assertArrayNotHasKey('Cookie', $request['headers']);
    }

    #[TestDox('Ответ не bencode отклоняется')]
    public function testNonBencodeResponseIsRejected(): void
    {
        $this->http->on('n.tracktor.site', new HttpResponse(
            200,
            ['content-disposition' => 'attachment;filename="x.torrent"'],
            '<html>заглушка</html>',
        ));

        $error = $this->rejected($this->client->fetchTorrent('https://n.tracktor.site/td.php?s=opaque'));

        self::assertStringContainsString('bencode', $error->getMessage());
    }

    #[TestDox('Торрент без имени отклоняется')]
    public function testTorrentWithoutFileNameIsRejected(): void
    {
        $this->http->on('n.tracktor.site', new HttpResponse(200, [], Fixtures::read('sample.torrent')));

        $error = $this->rejected($this->client->fetchTorrent('https://n.tracktor.site/td.php?s=opaque'));

        self::assertInstanceOf(RuntimeException::class, $error);
    }

    #[TestDox('Неуспешный статус ленты отклоняется')]
    public function testUnsuccessfulFeedStatusIsRejected(): void
    {
        $this->http->on('/rss.xml', new HttpResponse(503, [], 'сервис недоступен'));

        $error = $this->rejected($this->client->fetchRss());

        self::assertStringContainsString('503', $error->getMessage());
    }
}
