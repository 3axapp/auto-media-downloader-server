<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Job;

enum JobStatus: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    // Клиент забрал торрент - переотдача больше не нужна.
    case Acked = 'acked';
    case Done = 'done';
    case Failed = 'failed';
}
