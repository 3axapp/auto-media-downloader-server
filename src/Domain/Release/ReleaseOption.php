<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Release;

/**
 * Информация по релизу с качеством QualityLabel.
 */
final readonly class ReleaseOption
{
    public function __construct(
        public QualityLabel $quality,
        public string $url,
        public string $description,
    ) {}
}
