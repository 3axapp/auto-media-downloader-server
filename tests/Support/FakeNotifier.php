<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Support;

use Zakharov\AutoMediaDownloaderServer\Application\Notifier;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class FakeNotifier implements Notifier
{
    /**
     * @var list<array{text:string, photo:string|null}>
     */
    public array $sent = [];

    public function notify(string $text, ?string $photoUrl = null): PromiseInterface
    {
        $this->sent[] = ['text' => $text, 'photo' => $photoUrl];

        return resolve(null);
    }
}
