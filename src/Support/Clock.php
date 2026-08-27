<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;

    public function timestamp(): int;
}
