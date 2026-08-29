<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Http;


use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

final class ReactHttpClient implements HttpClient
{
    private Browser $browser;

    public function __construct(?Browser $browser = null, float $timeout = 30.0)
    {
        // Оба ключа обязательны:
        // withFollowRedirects(false) - иначе 302 от протухшей сессии превратится в 200 и защёлка алерта никогда не сработает;
        // withRejectErrorResponse(false) - иначе 4xx/5xx прилетят исключением вместо ответа.
        $this->browser = ($browser ?? new Browser())
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false)
            ->withTimeout($timeout);
    }

    public function get(string $url, array $headers = []): PromiseInterface
    {
        return $this->browser->get($url, $headers)->then($this->convert(...));
    }

    public function post(string $url, array $headers = [], string $body = ''): PromiseInterface
    {
        return $this->browser->post($url, $headers, $body)->then($this->convert(...));
    }

    private function convert(ResponseInterface $response): HttpResponse
    {
        return new HttpResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string)$response->getBody(),
        );
    }
}
