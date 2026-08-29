<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Job;

use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;

final readonly class Job
{
    public function __construct(
        public int $id,
        public int $seriesId,
        public string $seriesName,
        public string $seriesNameEn,
        public int $season,
        public int $episode,
        public QualityLabel $quality,
        public string $torrentName,
        public JobStatus $status,
        public int $attempts,
        public ?int $leaseUntil,
    ) {}

    /**
     * `Безумцы S07E06` - заготовка для уведомлений и логов.
     * @return string
     */
    public function label(): string
    {
        return sprintf(
            '%s S%02dE%02d',
            $this->seriesName !== '' ? $this->seriesName : (string)$this->seriesId,
            $this->season,
            $this->episode,
        );
    }
}
