<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Watcher;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Application\Watcher\WatchEpisodes;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\RssParser;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\ReleasePageParser;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\SearchRedirectParser;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\LostfilmClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Session\ConfigCookieProvider;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Session\SessionProvider;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\EpisodeRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeHttpClient;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeNotifier;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\PromiseAssertions;

final class WatchEpisodesTest extends TestCase
{
    use PromiseAssertions;

    private SqliteConnection $db;
    private FakeHttpClient $http;
    private FakeNotifier $notifier;
    private SeriesRepository $series;
    private EpisodeRepository $episodes;
    private JobRepository $jobs;

    protected function setUp(): void
    {
        $this->db = SqliteConnection::memory();
        $this->db->migrate();

        $clock = new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00'));
        $this->series = new SeriesRepository($this->db, $clock);
        $this->episodes = new EpisodeRepository($this->db, $clock);
        $this->jobs = new JobRepository($this->db, $clock);
        $this->notifier = new FakeNotifier();

        $this->http = (new FakeHttpClient())
            ->on('/rss.xml', new HttpResponse(200, [], Fixtures::read('rss.xml')))
            ->on('/v_search.php', new HttpResponse(200, [], Fixtures::read('v_search.html')))
            ->on('/V/?', new HttpResponse(200, [], Fixtures::read('release_page.html')))
            ->on('n.tracktor.site', new HttpResponse(
                200,
                ['content-disposition' => 'attachment;filename="Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent"'],
                Fixtures::read('sample.torrent'),
            ));
    }

    private function watcher(
        QualityLabel $quality = QualityLabel::Mp4,
        ?SessionProvider $session = null,
    ): WatchEpisodes {
        $lostfilm = new LostfilmClient(
            $this->http,
            $session ?? new ConfigCookieProvider('session-value', null),
            new SearchRedirectParser(),
        );

        return new WatchEpisodes(
            $lostfilm,
            new RssParser(),
            new ReleasePageParser(),
            $this->series,
            $this->episodes,
            $this->jobs,
            $this->db,
            $this->notifier,
            $quality,
            new Logger(fopen('php://memory', 'r+')),
            // new Logger(fopen('php://stdout', 'r+')),
        );
    }

    /**
     * Прямой счёт строк оставлен только там, где у репозитория нет неразрушающего чтения:
     * `JobRepository::lease()` меняет статус и попытки, поэтому подсчитать задания им нельзя.
     * Где публичный интерфейс есть - используется он (`SeriesRepository::all()`, `EpisodeRepository::has()`).
     * Имя таблицы - литерал внутри теста, во внешний ввод оно не попадает.
     */
    private function countRows(string $table): int
    {
        return (int) $this->db->pdo()->query("SELECT count(*) FROM {$table}")->fetchColumn();
    }

    /**
     * @return list<array{text:string, photo:null|string}>
     */
    private function sessionAlerts(): array
    {
        return array_values(array_filter(
            $this->notifier->sent,
            static fn (array $message): bool => str_contains(mb_strtolower($message['text']), 'сесси'),
        ));
    }

    #[TestDox('Проход ставит задания по всем эпизодам, кроме фильмов')]
    public function testPassEnqueuesEveryEpisodeExceptMovies(): void
    {
        $queued = $this->resolved($this->watcher()->run());

        self::assertSame(13, $queued);
        self::assertSame(13, $this->countRows('episodes'));
        self::assertSame(13, $this->countRows('jobs'));
        self::assertCount(9, $this->series->all());
        self::assertCount(13, $this->notifier->sent);
    }

    #[TestDox('Фильмы до цепочки резолва не доходят')]
    public function testMoviesNeverReachResolveChain(): void
    {
        $this->resolved($this->watcher()->run());

        // Два фильма из 15 элементов отсеиваются на EpisodeRef::tryFrom.
        self::assertCount(13, $this->http->requestsTo('/v_search.php'));
    }

    #[TestDox('Повторный проход ничего не добавляет')]
    public function testSecondPassAddsNothing(): void
    {
        $this->resolved($this->watcher()->run());
        $this->notifier->sent = [];

        $queued = $this->resolved($this->watcher()->run());

        self::assertSame(0, $queued);
        self::assertSame(13, $this->countRows('jobs'));
        self::assertSame([], $this->notifier->sent);
    }

    #[TestDox('Отключённый сериал пропускается')]
    public function testDisabledSeriesIsSkipped(): void
    {
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '');
        $this->series->setActive(1136, false);

        $queued = $this->resolved($this->watcher()->run());

        // У Mad Men в ленте четыре эпизода.
        self::assertSame(9, $queued);
        self::assertFalse($this->episodes->has(new EpisodeRef(1136, 7, 6), QualityLabel::Mp4));
    }

    #[TestDox('Нужного качества нет - эпизод не помечается обработанным')]
    public function testMissingQualityLeavesEpisodeUnprocessed(): void
    {
        $this->http->on('/V/?', new HttpResponse(200, [], <<<'HTML'
            <div class="inner-box--item">
              <div class="inner-box--label">SD</div>
              <div class="inner-box--link main"><a href="https://n.tracktor.site/td.php?s=SD">WEB-DLRip</a></div>
              <div class="inner-box--desc">Видео: WEB-DLRip</div>
            </div>
            HTML));

        $queued = $this->resolved($this->watcher(QualityLabel::Hd1080)->run());

        self::assertSame(0, $queued);
        self::assertSame(0, $this->countRows('episodes'));
        self::assertSame(0, $this->countRows('jobs'));
        // Проход не прерывается: остальные эпизоды тоже просматриваются.
        self::assertCount(13, $this->http->requestsTo('/V/?'));
    }

    #[TestDox('Сбой на торренте не помечает эпизод обработанным и не прерывает проход')]
    public function testTorrentFailureLeavesEpisodeUnprocessedAndContinuesPass(): void
    {
        $this->http->on('n.tracktor.site', new HttpResponse(500, [], ''));

        $queued = $this->resolved($this->watcher()->run());

        self::assertSame(0, $queued);
        self::assertSame(0, $this->countRows('episodes'));
        self::assertCount(13, $this->http->requestsTo('n.tracktor.site'));
    }

    #[TestDox('Мёртвая сессия прерывает проход и даёт ровно одно сообщение')]
    public function testDeadSessionAbortsPassWithSingleMessage(): void
    {
        $this->http->on('/v_search.php', new HttpResponse(302, ['location' => '/'], ''));
        $watcher = $this->watcher();

        $this->resolved($watcher->run());
        $this->resolved($watcher->run());

        // Защёлка: без неё мёртвый lf_session даёт 13 сообщений за проход, каждые 15 минут.
        self::assertCount(1, $this->sessionAlerts());
        // Перенос строки должен быть настоящим, а не литералом из двух символов.
        self::assertStringContainsString("\n", $this->sessionAlerts()[0]['text']);
        // Каждый проход упирается в первый же эпизод и прерывается: два прохода - два запроса.
        self::assertCount(2, $this->http->requestsTo('/v_search.php'));
    }

    #[TestDox('Защёлка снимается, когда сессия ожила')]
    public function testLatchResetsWhenSessionRecovers(): void
    {
        $watcher = $this->watcher();

        $this->http->on('/v_search.php', new HttpResponse(302, ['location' => '/'], ''));
        $this->resolved($watcher->run());
        self::assertCount(1, $this->sessionAlerts());

        // Cookie заменили в файле - v_search снова отвечает 200.
        $this->http->on('/v_search.php', new HttpResponse(200, [], Fixtures::read('v_search.html')));
        self::assertSame(13, $this->resolved($watcher->run()));

        // Дедупликация по (series, season, episode, quality) - шаг 4 спеки §7 - стоит раньше
        // v_search (шаг 5), поэтому при полностью обработанной ленте мёртвая сессия и не должна
        // обнаруживаться: резолв просто не вызывается. Чтобы третий проход снова дошёл
        // до v_search, чистим журнал дедупликации - как будто лента сменилась
        // (в проде она ротируется за сутки-двое).
        $this->db->pdo()->exec('DELETE FROM episodes');

        // И снова протухла: сообщение должно уйти повторно.
        $this->http->on('/v_search.php', new HttpResponse(302, ['location' => '/'], ''));
        $this->resolved($watcher->run());

        self::assertCount(2, $this->sessionAlerts());
    }

    #[TestDox('Недоступный файл сессии прерывает проход и даёт одно сообщение')]
    public function testUnreadableSessionFileAbortsPassWithSingleMessage(): void
    {
        // Опечатка в LF_SESSION_FILE или пустая запись - самый частый эксплуатационный отказ.
        // Он не должен молчать: сообщение уходит по той же ветке, что и протухший cookie.
        $watcher = $this->watcher(session: new ConfigCookieProvider(null, '/нет/такого/файла/lf_session'));

        $this->resolved($watcher->run());
        $this->resolved($watcher->run());

        self::assertCount(1, $this->sessionAlerts());
        self::assertSame([], $this->http->requestsTo('/v_search.php'));
    }

    #[TestDox('Задание содержит байты торрента и имя файла')]
    public function testJobCarriesTorrentBytesAndFileName(): void
    {
        $this->resolved($this->watcher()->run());

        $leased = $this->jobs->lease(1, 600);

        self::assertCount(1, $leased);
        self::assertSame('Mad.Men.S07E06.720p.rus.LostFilm.TV.mp4.torrent', $leased[0]->torrentName);
        self::assertSame(QualityLabel::Mp4, $leased[0]->quality);
        self::assertStringStartsWith('d', (string) $this->jobs->torrentBlob($leased[0]->id));
    }

    #[TestDox('Уведомление содержит названия и постер')]
    public function testNotificationCarriesNamesAndPoster(): void
    {
        $this->resolved($this->watcher()->run());

        $first = $this->notifier->sent[0];

        self::assertStringContainsString('Безумцы', $first['text']);
        self::assertStringContainsString('S07E06', $first['text']);
        self::assertSame('https://www.lostfilm.tv/Static/Images/1136/Posters/image.jpg', $first['photo']);
    }
}
