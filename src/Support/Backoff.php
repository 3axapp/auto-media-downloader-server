<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Support;

/**
 * Экспоненциальный бэкофф с потолком - для одиночных запросов вне цепочки резолва.
 */
final class Backoff
{
    private float $current;

    public function __construct(
        private readonly float $min = 1.0,
        private readonly float $max = 60.0,
    ) {
        $this->current = $min;
    }

    public function next(): float
    {
        $delay = $this->current;
        $this->current = min($this->current * 2, $this->max);

        return $delay;
    }

    public function reset(): void
    {
        $this->current = $this->min;
    }
}
