<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Session;

use RuntimeException;

/**
 * Aвтологин отложен, но должен встать сюда без переделки вызывающего кода
 */
interface SessionProvider
{
    /**
     * Значение cookie `lf_session`. Бросает RuntimeException, если сессии нет.
     * @return string
     * @throws RuntimeException
     */
    public function cookie(): string;
}
