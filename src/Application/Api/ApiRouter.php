<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Application\Api;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use Throwable;
use Zakharov\AutoMediaDownloaderServer\Application\Notifier;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\Job;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;

/**
 * Маршруты. Всё, кроме `/health`, требует `Authorization: Bearer <API_TOKEN>`
 * `ack` и `complete` разделены намеренно: `ack` снимает риск переотдачи сразу,
 * а `complete` приходит часами позже
 */
final readonly class ApiRouter
{
    public function __construct(
        private JobRepository $jobs,
        private SeriesRepository $series,
        private Notifier $notifier,
        private Logger $log,
        private string $token,
        private int $leaseTtl,
        private string $posterBase = 'https://www.lostfilm.tv',
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'GET' && $path === '/health') {
            return $this->json(200, ['status' => 'ok']);
        }

        if (!$this->authorized($request)) {
            $this->log->warn('отказ авторизации', ['path' => $path]);

            return $this->json(401, ['error' => 'нужен Authorization: Bearer <API_TOKEN>']);
        }

        if ($method === 'GET' && $path === '/jobs') {
            return $this->lease($request);
        }

        if ($method === 'GET' && preg_match('~^/jobs/(\d+)/torrent$~', $path, $m) === 1) {
            return $this->torrent((int)$m[1]);
        }

        if ($method === 'POST' && preg_match('~^/jobs/(\d+)/ack$~', $path, $m) === 1) {
            return $this->jobs->ack((int)$m[1])
                ? new Response(204)
                : $this->json(404, ['error' => 'задание не найдено']);
        }

        if ($method === 'POST' && $path === '/hooks/complete') {
            return $this->complete($request);
        }

        return $this->json(404, ['error' => 'маршрут не найден']);
    }

    private function authorized(ServerRequestInterface $request): bool
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        return hash_equals($this->token, substr($header, 7));
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws Throwable
     */
    private function lease(ServerRequestInterface $request): ResponseInterface
    {
        $limit = (int)($request->getQueryParams()['limit'] ?? 10);
        $limit = max(1, min($limit, 100));

        $jobs = $this->jobs->lease($limit, $this->leaseTtl);

        return $this->json(200, array_map($this->present(...), $jobs));
    }

    /**
     * @param Job $job
     * @return array<string, mixed>
     */
    private function present(Job $job): array
    {
        return [
            'id'           => $job->id,
            'seriesId'     => $job->seriesId,
            'seriesName'   => $job->seriesName,
            'seriesNameEn' => $job->seriesNameEn,
            'season'       => $job->season,
            'episode'      => $job->episode,
            'quality'      => $job->quality->value,
            'torrentName'  => $job->torrentName,
            'torrentUrl'   => '/jobs/' . $job->id . '/torrent',
            'leaseUntil'   => $job->leaseUntil === null ? null : gmdate('Y-m-d\TH:i:s\Z', $job->leaseUntil),
        ];
    }

    private function torrent(int $id): ResponseInterface
    {
        $job = $this->jobs->find($id);
        $blob = $this->jobs->torrentBlob($id);

        if ($job === null || $blob === null) {
            return $this->json(404, ['error' => 'байты торрента недоступны']);
        }

        return new Response(200, [
            'Content-Type'        => 'application/x-bittorrent',
            'Content-Disposition' => 'attachment; filename="' . $job->torrentName . '"',
        ], $blob);
    }


    private function complete(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $payload = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(400, ['error' => 'тело запроса не является JSON']);
        }

        $id = is_array($payload) ? (int)($payload['jobId'] ?? 0) : 0;
        $status = is_array($payload) ? (string)($payload['status'] ?? '') : '';

        if ($id <= 0 || !in_array($status, ['ok', 'error'], true)) {
            return $this->json(400, ['error' => 'ожидались поля jobId и status (ok|error)']);
        }

        $job = $this->jobs->complete($id, $status === 'ok' ? JobStatus::Done : JobStatus::Failed);

        if ($job === null) {
            return $this->json(404, ['error' => 'задание не найдено']);
        }

        $this->log->info('клиент отчитался', ['job' => $id, 'status' => $status]);
        $this->announce($job, $status, is_array($payload) ? $payload : []);

        return new Response(204);
    }

    /**
     * @param Job $job
     * @param string $status
     * @param array<string, mixed> $payload
     * @return void
     */
    private function announce(Job $job, string $status, array $payload): void
    {
        $poster = $this->series->posterPath($job->seriesId);
        $poster = $poster === null ? null : $this->posterBase . $poster;

        $text = $status === 'ok'
            ? 'Скачано: ' . $job->label() . ' (' . $job->torrentName . ')'
            // Переотдачи не происходит: клиент торрент получил, проблема на его стороне.
            : 'Ошибка скачивания: ' . $job->label() . "\n" . (string)($payload['error'] ?? 'без описания');

        $this->notifier
            ->notify($text, $status === 'ok' ? $poster : null)
            ->then(null, function (Throwable $e): void {
                $this->log->warn('уведомление не ушло', ['ошибка' => $e->getMessage()]);
            });
    }

    private function json(int $status, mixed $payload): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
