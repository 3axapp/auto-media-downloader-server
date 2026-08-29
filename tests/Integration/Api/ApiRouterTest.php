<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Api;

use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;
use Zakharov\AutoMediaDownloaderServer\Application\Api\ApiRouter;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeNotifier;

final class ApiRouterTest extends TestCase
{
    private const TOKEN = 'секретный-токен';
    private const BLOB = "d8:announce20:http://example/annce4:infod4:name9:dummy.mp4ee";

    private JobRepository $jobs;
    private FakeNotifier $notifier;
    private ApiRouter $api;
    private int $jobId;

    protected function setUp(): void
    {
        $db = SqliteConnection::memory();
        $db->migrate();

        $clock = new FakeClock(new DateTimeImmutable('2026-08-24T18:30:00+00:00'));
        $series = new SeriesRepository($db, $clock);
        $series->upsert(1136, 'Безумцы', 'Mad Men', '/Static/Images/1136/Posters/image.jpg');

        $this->jobs = new JobRepository($db, $clock);
        $this->jobId = $this->jobs->enqueue(
            new EpisodeRef(1136, 7, 6),
            QualityLabel::Mp4,
            'Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent',
            self::BLOB,
        );

        $this->notifier = new FakeNotifier();
        $this->api = new ApiRouter(
            $this->jobs,
            $series,
            $this->notifier,
            new Logger(fopen('php://memory', 'r+')),
            self::TOKEN,
            600,
        );
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, string> $headers
     * @param string $body
     * @return array
     */
    private function call(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $response = ($this->api)(new ServerRequest($method, 'http://amd.local' . $path, $headers, $body));

        return [$response->getStatusCode(), (string) $response->getBody(), $response];
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function authorized(array $extra = []): array
    {
        return ['Authorization' => 'Bearer ' . self::TOKEN] + $extra;
    }

    #[TestDox('Health доступен без авторизации')]
    public function testHealthIsPublic(): void
    {
        [$status, $body] = $this->call('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame('ok', json_decode($body, true)['status']);
    }

    #[TestDox('Без токена доступ запрещён')]
    public function testWithoutTokenAccessIsDenied(): void
    {
        self::assertSame(401, $this->call('GET', '/jobs')[0]);
        self::assertSame(401, $this->call('GET', '/jobs', ['Authorization' => 'Bearer не-тот'])[0]);
    }

    /**
     * @throws JsonException
     */
    #[TestDox('Выдача заданий возвращает контракт спеки')]
    public function testJobsResponseMatchesSpecContract(): void
    {
        [$status, $body] = $this->call('GET', '/jobs?limit=10', $this->authorized());

        self::assertSame(200, $status);
        $jobs = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $jobs);
        self::assertSame([
            'id' => $this->jobId,
            'seriesId' => 1136,
            'seriesName' => 'Безумцы',
            'seriesNameEn' => 'Mad Men',
            'season' => 7,
            'episode' => 6,
            'quality' => 'MP4',
            'torrentName' => 'Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent',
            'torrentUrl' => '/jobs/' . $this->jobId . '/torrent',
            'leaseUntil' => '2026-08-24T18:40:00Z',
        ], $jobs[0]);
    }

    #[TestDox('Повторная выдача не отдаёт залиженное задание')]
    public function testSecondLeaseSkipsLeasedJob(): void
    {
        $this->call('GET', '/jobs', $this->authorized());

        [$status, $body] = $this->call('GET', '/jobs', $this->authorized());

        self::assertSame(200, $status);
        self::assertSame([], json_decode($body, true));
    }

    #[TestDox('Лимит по умолчанию и из запроса')]
    public function testLimitDefaultAndFromQuery(): void
    {
        $this->jobs->enqueue(new EpisodeRef(1136, 7, 5), QualityLabel::Mp4, 'a.torrent', self::BLOB);
        $this->jobs->enqueue(new EpisodeRef(1136, 7, 4), QualityLabel::Mp4, 'b.torrent', self::BLOB);

        [, $body] = $this->call('GET', '/jobs?limit=2', $this->authorized());

        self::assertCount(2, json_decode($body, true));
    }

    #[TestDox('Байты торрента отдаются с заголовками')]
    public function testTorrentBytesComeWithHeaders(): void
    {
        [$status, $body, $response] = $this->call('GET', "/jobs/{$this->jobId}/torrent", $this->authorized());

        self::assertSame(200, $status);
        self::assertSame(self::BLOB, $body);
        self::assertSame('application/x-bittorrent', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent"',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    #[TestDox('Торрент несуществующего задания даёт 404')]
    public function testTorrentOfUnknownJobIs404(): void
    {
        self::assertSame(404, $this->call('GET', '/jobs/999/torrent', $this->authorized())[0]);
    }

    #[TestDox('Ack обнуляет байты и снимает лизинг')]
    public function testAckClearsBytesAndLease(): void
    {
        $this->call('GET', '/jobs', $this->authorized());

        [$status] = $this->call('POST', "/jobs/{$this->jobId}/ack", $this->authorized());

        self::assertSame(204, $status);
        self::assertSame(JobStatus::Acked, $this->jobs->find($this->jobId)?->status);
        self::assertSame(404, $this->call('GET', "/jobs/{$this->jobId}/torrent", $this->authorized())[0]);
    }

    #[TestDox('Ack несуществующего задания даёт 404')]
    public function testAckOfUnknownJobIs404(): void
    {
        self::assertSame(404, $this->call('POST', '/jobs/999/ack', $this->authorized())[0]);
    }

    #[TestDox('Успешное завершение переводит в done и уведомляет')]
    public function testSuccessfulCompletionMarksDoneAndNotifies(): void
    {
        $payload = json_encode(['jobId' => $this->jobId, 'status' => 'ok', 'path' => '/media/mad_men.mp4']);

        [$status] = $this->call('POST', '/hooks/complete', $this->authorized(['Content-Type' => 'application/json']), $payload);

        self::assertSame(204, $status);
        self::assertSame(JobStatus::Done, $this->jobs->find($this->jobId)?->status);
        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('Безумцы', $this->notifier->sent[0]['text']);
        self::assertSame('https://www.lostfilm.tv/Static/Images/1136/Posters/image.jpg', $this->notifier->sent[0]['photo']);
    }

    #[TestDox('Завершение с ошибкой переводит в failed и передаёт текст')]
    public function testErrorCompletionMarksFailedAndForwardsText(): void
    {
        $payload = json_encode(['jobId' => $this->jobId, 'status' => 'error', 'error' => 'нет места на диске']);

        [$status] = $this->call('POST', '/hooks/complete', $this->authorized(), $payload);

        self::assertSame(204, $status);
        self::assertSame(JobStatus::Failed, $this->jobs->find($this->jobId)?->status);
        self::assertStringContainsString('нет места на диске', $this->notifier->sent[0]['text']);
    }

    #[TestDox('Кривое тело хука даёт 400')]
    public function testMalformedHookBodyIs400(): void
    {
        self::assertSame(400, $this->call('POST', '/hooks/complete', $this->authorized(), 'не json')[0]);
        self::assertSame(400, $this->call('POST', '/hooks/complete', $this->authorized(), '{"status":"ok"}')[0]);
        self::assertSame(400, $this->call('POST', '/hooks/complete', $this->authorized(), '{"jobId":1,"status":"странно"}')[0]);
    }

    #[TestDox('Хук по неизвестному заданию даёт 404')]
    public function testHookForUnknownJobIs404(): void
    {
        $payload = json_encode(['jobId' => 999, 'status' => 'ok']);

        self::assertSame(404, $this->call('POST', '/hooks/complete', $this->authorized(), $payload)[0]);
    }

    #[TestDox('Неизвестный маршрут даёт 404')]
    public function testUnknownRouteIs404(): void
    {
        self::assertSame(404, $this->call('GET', '/чего-то-нет', $this->authorized())[0]);
    }
}
