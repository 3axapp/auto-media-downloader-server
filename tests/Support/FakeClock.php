<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Support;

use DateMalformedStringException;
use DateTimeImmutable;
use Zakharov\AutoMediaDownloaderServer\Support\Clock;

final class FakeClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * @param int $seconds
     * @return void
     * @throws DateMalformedStringException
     */
    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
