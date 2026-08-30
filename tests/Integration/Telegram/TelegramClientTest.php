<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Telegram;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Telegram\TelegramClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Telegram\TelegramNotifier;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeHttpClient;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\PromiseAssertions;

final class TelegramClientTest extends TestCase
{
    use PromiseAssertions;

    private FakeHttpClient $http;
    private TelegramClient $telegram;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->telegram = new TelegramClient($this->http, 'токен-бота');
    }

    #[TestDox('getUpdates передаёт offset и таймаут не более пятидесяти')]
    public function testGetUpdatesSendsOffsetAndCappedTimeout(): void
    {
        $this->http->on('/getUpdates', new HttpResponse(200, [], json_encode([
            'ok' => true,
            'result' => [['update_id' => 7, 'message' => ['text' => '/help']]],
        ])));

        $updates = $this->resolved($this->telegram->getUpdates(42, 1000));

        self::assertCount(1, $updates);
        self::assertSame(7, $updates[0]['update_id']);

        $request = $this->http->requestsTo('/getUpdates')[0];
        self::assertStringContainsString('/bot' . 'токен-бота' . '/getUpdates', $request['url']);
        $payload = json_decode($request['body'], true);
        self::assertSame(42, $payload['offset']);
        // Легаси слал timeout=1000; допустимый максимум — 50, Telegram молча урезает.
        self::assertSame(50, $payload['timeout']);
    }

    #[TestDox('Ответ не ok отклоняется')]
    public function testNotOkResponseIsRejected(): void
    {
        $this->http->on('/getUpdates', new HttpResponse(200, [], json_encode([
            'ok' => false,
            'description' => 'Unauthorized',
        ])));

        $error = $this->rejected($this->telegram->getUpdates(0));

        self::assertStringContainsString('Unauthorized', $error->getMessage());
    }

    #[TestDox('Конфликт 409 распознаётся отдельно')]
    public function testConflictIsRecognizedSeparately(): void
    {
        $this->http->on('/getUpdates', new HttpResponse(409, [], json_encode([
            'ok' => false,
            'description' => 'Conflict: terminated by other getUpdates request',
        ])));

        $error = $this->rejected($this->telegram->getUpdates(0));

        self::assertStringContainsString('409', $error->getMessage());
        self::assertStringContainsString('экземпляр', mb_strtolower($error->getMessage()));
    }

    #[TestDox('Отправка сообщения и фото')]
    public function testSendsMessageAndPhoto(): void
    {
        $this->http->on('/sendMessage', new HttpResponse(200, [], json_encode(['ok' => true, 'result' => []])));
        $this->http->on('/sendPhoto', new HttpResponse(200, [], json_encode(['ok' => true, 'result' => []])));

        $this->resolved($this->telegram->sendMessage(100500, "строка\nвторая"));
        $this->resolved($this->telegram->sendPhoto(100500, 'https://www.lostfilm.tv/poster.jpg', 'подпись'));

        $message = json_decode($this->http->requestsTo('/sendMessage')[0]['body'], true);
        self::assertSame(100500, $message['chat_id']);
        self::assertSame("строка\nвторая", $message['text']);

        $photo = json_decode($this->http->requestsTo('/sendPhoto')[0]['body'], true);
        self::assertSame('https://www.lostfilm.tv/poster.jpg', $photo['photo']);
        self::assertSame('подпись', $photo['caption']);
    }

    #[TestDox('Уведомитель рассылает во все чаты и не падает на ошибке')]
    public function testNotifierFansOutAndSwallowsErrors(): void
    {
        $this->http->on('/sendMessage', new HttpResponse(200, [], json_encode(['ok' => true, 'result' => []])));
        $this->http->on('/sendPhoto', new HttpResponse(500, [], ''));

        $notifier = new TelegramNotifier($this->telegram, [100500, 100501], new Logger(fopen('php://memory', 'r+')));

        $this->resolved($notifier->notify('текст'));
        self::assertCount(2, $this->http->requestsTo('/sendMessage'));

        // Ошибка отправки не должна отклонять промис: уведомления совещательны.
        $this->resolved($notifier->notify('текст', 'https://www.lostfilm.tv/poster.jpg'));
        self::assertCount(2, $this->http->requestsTo('/sendPhoto'));
    }
}
