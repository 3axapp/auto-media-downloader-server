<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Application\Watcher;

use Throwable;
use Zakharov\AutoMediaDownloaderServer\Application\Notifier;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;

final readonly class ExpireStaleJobs
{
    public function __construct(
        private JobRepository $jobs,
        private Notifier $notifier,
        private Logger $log,
        private int $maxAttempts,
    ) {}

    /**
     * @return void
     * @throws Throwable
     */
    public function run(): void
    {
        foreach ($this->jobs->expire($this->maxAttempts) as $job) {
            $this->log->error('задание не забрали', ['job' => $job->id, 'попыток' => $job->attempts]);

            $this->notifier
                ->notify('Задание не забрали за ' . $this->maxAttempts . ' выдач: ' . $job->label())
                ->then(null, function (Throwable $e): void {
                    $this->log->warn('уведомление не ушло', ['ошибка' => $e->getMessage()]);
                });
        }
    }
}
