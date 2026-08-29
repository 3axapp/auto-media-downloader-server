<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Support;

use PHPUnit\Framework\Assert;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Фейковый клиент отдаёт уже разрешённые промисы, поэтому цепочка `then`
 * выполняется синхронно - event loop в тестах не нужен.
 */
trait PromiseAssertions
{
    private function resolved(PromiseInterface $promise): mixed
    {
        $value = null;
        $settled = false;
        $failure = null;

        $promise->then(
            function ($result) use (&$value, &$settled): void {
                $value = $result;
                $settled = true;
            },
            function (Throwable $e) use (&$failure, &$settled): void {
                $failure = $e;
                $settled = true;
            },
        );

        Assert::assertTrue($settled, 'Промис не разрешился синхронно');
        Assert::assertNull($failure, 'Промис отклонён: ' . ($failure?->getMessage() ?? ''));

        return $value;
    }

    private function rejected(PromiseInterface $promise): Throwable
    {
        $failure = null;

        $promise->then(
            static function (): void {},
            function (Throwable $e) use (&$failure): void {
                $failure = $e;
            },
        );

        Assert::assertInstanceOf(Throwable::class, $failure, 'Промис не был отклонён');

        return $failure;
    }
}
