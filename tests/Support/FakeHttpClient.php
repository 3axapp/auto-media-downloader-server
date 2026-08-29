<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Support;

use React\Promise\PromiseInterface;
use RuntimeException;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Маршрутизация по подстроке URL обязательна: в ленте один сериал встречается
 * несколько раз (Mad Men - 4 элемента), и клиент с единственным ответом
 * сделал бы зелёным тест, который ничего не проверяет.
 */
final class FakeHttpClient implements HttpClient
{
    /**
     * @var list<array{method:string, url:string, headers:array<string,string>, body:string}>
     */
    public array $requests = [];

    /**
     * @var list<array{needle:string, response:HttpResponse|callable}>
     */
    private array $routes = [];

    /**
     * Повторный вызов с той же подстрокой заменяет ответ - так тест меняет поведение по ходу сценария.
     * @param string $urlSubstring
     * @param HttpResponse|callable $response
     * @return $this
     */
    public function on(string $urlSubstring, HttpResponse|callable $response): self
    {
        foreach ($this->routes as $index => $route) {
            if ($route['needle'] === $urlSubstring) {
                $this->routes[$index]['response'] = $response;

                return $this;
            }
        }

        $this->routes[] = ['needle' => $urlSubstring, 'response' => $response];

        return $this;
    }

    public function get(string $url, array $headers = []): PromiseInterface
    {
        return $this->respond('GET', $url, $headers, '');
    }

    public function post(string $url, array $headers = [], string $body = ''): PromiseInterface
    {
        return $this->respond('POST', $url, $headers, $body);
    }

    /**
     * @param string $substring
     * @return list<array{method:string, url:string, headers:array<string,string>, body:string}>
     */
    public function requestsTo(string $substring): array
    {
        return array_values(
            array_filter(
                $this->requests,
                static fn(array $request): bool => str_contains($request['url'], $substring),
            ),
        );
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string $body
     * @return PromiseInterface
     */
    private function respond(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($this->routes as $route) {
            if (!str_contains($url, $route['needle'])) {
                continue;
            }

            $response = $route['response'];

            return resolve($response instanceof HttpResponse ? $response : $response($url, $headers, $body));
        }

        return reject(new RuntimeException("Нет заглушки для {$method} {$url}"));
    }
}
