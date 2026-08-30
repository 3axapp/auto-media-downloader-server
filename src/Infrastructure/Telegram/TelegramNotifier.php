<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Telegram;

use React\Promise\PromiseInterface;
use Throwable;
use Zakharov\AutoMediaDownloaderServer\Application\Notifier;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;

use function React\Promise\all;
use function React\Promise\resolve;

final readonly class TelegramNotifier implements Notifier
{
    /**
     * @param TelegramClient $telegram
     * @param list<int|string> $chatIds
     * @param Logger $log
     */
    public function __construct(
        private TelegramClient $telegram,
        private array $chatIds,
        private Logger $log,
    ) {}

    public function notify(string $text, ?string $photoUrl = null): PromiseInterface
    {
        $sends = [];

        foreach ($this->chatIds as $chatId) {
            $send = $photoUrl === null
                ? $this->telegram->sendMessage($chatId, $text)
                : $this->telegram->sendPhoto($chatId, $photoUrl, $text);

            $sends[] = $send->then(null, function (Throwable $e) use ($chatId): null {
                $this->log->warn('сообщение не доставлено', ['chat' => $chatId, 'ошибка' => $e->getMessage()]);

                return null;
            });
        }

        return $sends ? all($sends)->then(static fn(): null => null) : resolve(null);
    }
}
