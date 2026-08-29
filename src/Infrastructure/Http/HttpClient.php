<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Http;

use React\Promise\PromiseInterface;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return PromiseInterface<HttpResponse>
     */
    public function get(string $url, array $headers = []): PromiseInterface;

    /**
     * @param array<string, string> $headers
     * @return PromiseInterface<HttpResponse>
     */
    public function post(string $url, array $headers = [], string $body = ''): PromiseInterface;
}
