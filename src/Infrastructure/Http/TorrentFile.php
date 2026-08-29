<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Http;

final readonly class TorrentFile
{
    public function __construct(
        public string $name,
        public string $bytes,
    ) {}
}
