<?php

declare(strict_types=1);

namespace Support;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Support\Backoff;

final class BackoffTest extends TestCase
{
    #[TestDox('Удваивается до потолка')]
    public function testDoublesUpToCeiling(): void
    {
        $backoff = new Backoff(1.0, 60.0);

        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 60.0, 60.0], [
            $backoff->next(), $backoff->next(), $backoff->next(), $backoff->next(),
            $backoff->next(), $backoff->next(), $backoff->next(), $backoff->next(),
        ]);
    }

    #[TestDox('Сброс возвращает к минимуму')]
    public function testResetReturnsToMinimum(): void
    {
        $backoff = new Backoff(1.0, 60.0);
        $backoff->next();
        $backoff->next();

        $backoff->reset();

        self::assertSame(1.0, $backoff->next());
    }
}
