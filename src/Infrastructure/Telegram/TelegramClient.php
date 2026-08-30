<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Telegram;

use React\Promise\PromiseInterface;
use RuntimeException;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;

final readonly class TelegramClient
{
    private const int MAX_TIMEOUT = 50;

    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $api = 'https://api.telegram.org',
    ) {}

    public function getUpdates(int $offset, int $timeout = self::MAX_TIMEOUT): PromiseInterface
    {
        return $this->call('getUpdates', [
            'offset'  => $offset,
            'timeout' => min($timeout, self::MAX_TIMEOUT),
        ])->then(static fn(mixed $result): array => is_array($result) ? $result : []);
    }

    public function sendMessage(int|string $chatId, string $text): PromiseInterface
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    public function sendPhoto(int|string $chatId, string $photoUrl, string $caption): PromiseInterface
    {
        return $this->call('sendPhoto', ['chat_id' => $chatId, 'photo' => $photoUrl, 'caption' => $caption]);
    }

    private function call(string $method, array $params = []): PromiseInterface
    {
        $url = sprintf('%s/bot%s/%s', $this->api, $this->token, $method);

        return $this->http
            ->post($url, ['Content-Type' => 'application/json'], json_encode($params, JSON_UNESCAPED_UNICODE))
            ->then(static function (HttpResponse $response) use ($method): mixed {
                if ($response->status === 409) {
                    throw new RuntimeException("Telegram ответил 409 на {$method}: запущен второй экземпляр демона");
                }

                $payload = json_decode($response->body, true);

                if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
                    $description = is_array($payload) ? (string)($payload['description'] ?? '') : $response->body;

                    throw new RuntimeException("Telegram отклонил {$method}: {$description}");
                }

                return $payload['result'] ?? null;
            });
    }
}
