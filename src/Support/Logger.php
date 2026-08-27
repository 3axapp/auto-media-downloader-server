<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Support;

use DateMalformedStringException;

/**
 * Однострочный лог в stderr: одна строка на событие, чтобы `docker compose logs`
 * оставался пригодным для чтения.
 */
final class Logger
{
    /** @var resource */
    private $stream;

    private Clock $clock;

    /** @param resource|null $stream */
    public function __construct($stream = null, ?Clock $clock = null)
    {
        $this->stream = $stream ?? STDERR;
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param array<string, scalar|null> $context
     * @throws DateMalformedStringException
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     * @throws DateMalformedStringException
     */
    public function warn(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     * @throws DateMalformedStringException
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     * @throws DateMalformedStringException
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = $this->clock->now()->format('c') . ' ' . $level . ' ' . $message;

        foreach ($context as $key => $value) {
            $line .= ' ' . $key . '=' . match (true) {
                    $value === null => 'null',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string)$value,
                };
        }

        fwrite($this->stream, $line . "\n");
    }
}
