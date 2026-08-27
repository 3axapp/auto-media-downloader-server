<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Support;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    /**
     * @return DateTimeImmutable
     * @throws DateMalformedStringException
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function timestamp(): int
    {
        return time();
    }
}

