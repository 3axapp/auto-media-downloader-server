<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Http;

use React\Promise\PromiseInterface;
use RuntimeException;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\SearchRedirectParser;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\TorrentFileName;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Session\SessionProvider;

final readonly class LostfilmClient
{
    public function __construct(
        private HttpClient $http,
        private SessionProvider $session,
        private SearchRedirectParser $redirects,
        private string $base = 'https://www.lostfilm.tv',
    ) {}


    /**
     * @return PromiseInterface<string>
     */
    public function fetchRss(): PromiseInterface
    {
        // Лента cookie не требует.
        return $this->http->get($this->base . '/rss.xml')->then(function (HttpResponse $response): string {
            if (!$response->isOk()) {
                throw new RuntimeException('RSS ответил статусом ' . $response->status);
            }

            return $response->body;
        });
    }

    /**
     * Абсолютный URL страницы релиза.
     * @param EpisodeRef $ref
     * @return PromiseInterface<string>
     */
    public function resolveSearch(EpisodeRef $ref): PromiseInterface
    {
        $url = sprintf('%s/v_search.php?c=%d&s=%d&e=%d', $this->base, $ref->seriesId, $ref->season, $ref->episode);

        return $this->http->get($url, $this->cookieHeader())->then(function (HttpResponse $response): string {
            // Без действующего cookie v_search.php отвечает редиректом - главный ожидаемый отказ.
            if ($response->status === 302 || $response->status === 301) {
                throw new SessionExpiredException('v_search.php ответил редиректом: сессия lf_session протухла');
            }

            if (!$response->isOk()) {
                throw new RuntimeException('v_search.php ответил статусом ' . $response->status);
            }

            $path = $this->redirects->parse($response->body);

            if ($path === null) {
                throw new RuntimeException('В ответе v_search.php нет ссылки на страницу релиза');
            }

            return str_starts_with($path, 'http') ? $path : $this->base . $path;
        });
    }

    /**
     * @param string $url
     * @return PromiseInterface<string>
     */
    public function fetchReleasePage(string $url): PromiseInterface
    {
        return $this->http->get($url, $this->cookieHeader())->then(function (HttpResponse $response): string {
            if (!$response->isOk()) {
                throw new RuntimeException('Страница релиза ответила статусом ' . $response->status);
            }

            return $response->body;
        });
    }

    /**
     * @param string $url
     * @return PromiseInterface<TorrentFile>
     */
    public function fetchTorrent(string $url): PromiseInterface
    {
        // Внешний хост: cookie сюда не идёт и не нужен.
        return $this->http->get($url)->then(function (HttpResponse $response): TorrentFile {
            if (!$response->isOk()) {
                throw new RuntimeException('Хост торрента ответил статусом ' . $response->status);
            }

            $name = TorrentFileName::fromContentDisposition($response->header('content-disposition'));

            if ($name === null) {
                throw new RuntimeException('В ответе нет Content-Disposition с именем файла');
            }

            if (!str_starts_with($response->body, 'd')) {
                throw new RuntimeException('Ответ не похож на bencode: получена заглушка вместо торрента');
            }

            return new TorrentFile($name, $response->body);
        });
    }

    /**
     * @return array<string, string>
     */
    private function cookieHeader(): array
    {
        return ['Cookie' => 'lf_session=' . $this->session->cookie()];
    }
}
