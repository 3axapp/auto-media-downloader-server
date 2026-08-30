<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Bot;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use Zakharov\AutoMediaDownloaderServer\Application\Bot\Commands;
use Zakharov\AutoMediaDownloaderServer\Application\Bot\PollTelegram;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\BotStateRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Telegram\TelegramClient;
use Zakharov\AutoMediaDownloaderServer\Support\Backoff;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeHttpClient;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\PromiseAssertions;

final class PollTelegramTest extends TestCase
{
    use PromiseAssertions;

    private FakeHttpClient $http;
    private BotStateRepository $state;
    private PollTelegram $poll;

    protected function setUp(): void
    {
        $db = SqliteConnection::memory();
        $db->migrate();

        $clock = new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00'));
        $series = new SeriesRepository($db, $clock);
        $series->upsert(1136, 'Безумцы', 'Mad Men', '');

        $this->state = new BotStateRepository($db);
        $this->http = new FakeHttpClient();
        $this->http->on('/sendMessage', new HttpResponse(200, [], json_encode(['ok' => true, 'result' => []])));

        $this->poll = new PollTelegram(
            new TelegramClient($this->http, 'токен-бота'),
            $this->state,
            new Commands($series, new JobRepository($db, $clock), $db),
            new Logger(fopen('php://memory', 'r+')),
            new Backoff(1.0, 60.0),
            Loop::get(),
        );
    }

    #[TestDox('Отвечает на команду и сдвигает offset')]
    public function testRepliesToCommandAndAdvancesOffset(): void
    {
        $this->resolved($this->poll->handleUpdates([
            ['update_id' => 7, 'message' => ['chat' => ['id' => 100500], 'text' => '/list']],
        ]));

        $sent = json_decode($this->http->requestsTo('/sendMessage')[0]['body'], true);
        self::assertSame(100500, $sent['chat_id']);
        self::assertStringContainsString('Безумцы', $sent['text']);

        // Offset хранится в bot_state взамен data/telegram.json из легаси.
        self::assertSame(8, $this->state->offset());
    }

    #[TestDox('Не команда — не вызывает ответа')]
    public function testNonCommandProducesNoReply(): void
    {
        $this->resolved($this->poll->handleUpdates([
            ['update_id' => 9, 'message' => ['chat' => ['id' => 100500], 'text' => 'просто текст']],
        ]));

        self::assertSame([], $this->http->requestsTo('/sendMessage'));
        self::assertSame(10, $this->state->offset());
    }

    #[TestDox('Апдейт без текста не ломает обработку')]
    public function testUpdateWithoutTextDoesNotBreakHandling(): void
    {
        $this->resolved($this->poll->handleUpdates([
            ['update_id' => 11, 'edited_message' => ['chat' => ['id' => 100500]]],
        ]));

        self::assertSame(12, $this->state->offset());
    }

    #[TestDox('Offset не откатывается назад')]
    public function testOffsetNeverGoesBackwards(): void
    {
        $this->state->setOffset(100);

        $this->resolved($this->poll->handleUpdates([
            ['update_id' => 7, 'message' => ['chat' => ['id' => 100500], 'text' => '/help']],
        ]));

        self::assertSame(100, $this->state->offset());
    }
}
