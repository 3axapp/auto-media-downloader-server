<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Application;

use React\Promise\PromiseInterface;

interface Notifier
{
    public function notify(string $text, ?string $photoUrl = null): PromiseInterface;
}
