<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Application\Bot;

use DateMalformedStringException;
use Throwable;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\BotStateRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Telegram\TelegramClient;
use Zakharov\AutoMediaDownloaderServer\Support\Backoff;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;

use function React\Promise\all;
use function React\Promise\resolve;

final class PollTelegram
{
    private bool $running = false;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly BotStateRepository $state,
        private readonly Commands $commands,
        private readonly Logger $log,
        private readonly Backoff $backoff,
        private readonly LoopInterface $loop,
    ) {}

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->tick();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function tick(): void
    {
        if (!$this->running) {
            return;
        }

        $this->telegram
            ->getUpdates($this->state->offset())
            ->then(function (array $updates): PromiseInterface {
                $this->backoff->reset();

                return $this->handleUpdates($updates);
            })
            ->then(
                fn() => $this->tick(),
                function (Throwable $e): void {
                    $delay = $this->backoff->next();
                    $this->log->error('опрос Telegram не удался', [
                        'ошибка' => $e->getMessage(),
                        'пауза'  => $delay,
                    ]);
                    $this->loop->addTimer($delay, fn() => $this->tick());
                },
            );
    }

    /**
     * @param array $updates
     * @return PromiseInterface
     * @throws Throwable
     * @throws DateMalformedStringException
     */
    public function handleUpdates(array $updates): PromiseInterface
    {
        $replies = [];

        foreach ($updates as $update) {
            $updateId = (int)($update['update_id'] ?? 0);

            if ($updateId >= $this->state->offset()) {
                $this->state->setOffset($updateId + 1);
            }

            $message = $update['message'] ?? null;

            if (!is_array($message)) {
                continue;
            }

            $text = (string)($message['text'] ?? '');
            $chatId = $message['chat']['id'] ?? null;
            $reply = $this->commands->handle($text);

            if ($reply === null || $chatId === null) {
                continue;
            }

            $this->log->info('команда бота', ['chat' => $chatId, 'команда' => $text]);

            $replies[] = $this->telegram
                ->sendMessage($chatId, $reply)
                ->then(null, function (Throwable $e): null {
                    $this->log->warn('ответ не доставлен', ['ошибка' => $e->getMessage()]);

                    return null;
                });
        }

        return $replies ? all($replies)->then(static fn(): null => null) : resolve(null);
    }
}
