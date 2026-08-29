<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Application\Watcher;

use DateMalformedStringException;
use React\Promise\PromiseInterface;
use Throwable;
use Zakharov\AutoMediaDownloaderServer\Application\Notifier;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\FeedItem;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\RssParser;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\SeriesTitle;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\ReleasePageParser;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\LostfilmClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\SessionExpiredException;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\TorrentFile;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\EpisodeRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Support\Logger;

use function React\Promise\resolve;
use function React\Promise\reject;

/**
 * лента -> фильтры -> цепочка резолва -> одна транзакция -> уведомление
 */
final class WatchEpisodes
{
    /**
     * Защёлка алерта о протухшей сессии: одно сообщение, а не по одному на эпизод.
     */
    private bool $sessionAlertSent = false;

    public function __construct(
        private readonly LostfilmClient $lostfilm,
        private readonly RssParser $rss,
        private readonly ReleasePageParser $releases,
        private readonly SeriesRepository $series,
        private readonly EpisodeRepository $episodes,
        private readonly JobRepository $jobs,
        private readonly SqliteConnection $db,
        private readonly Notifier $notifier,
        private readonly QualityLabel $quality,
        private readonly Logger $log,
        private readonly string $posterBase = 'https://www.lostfilm.tv',
    ) {}

    /**
     * @return PromiseInterface<int>
     */
    public function run(): PromiseInterface
    {
        return $this->lostfilm
            ->fetchRss()
            ->then(fn(string $xml): PromiseInterface => $this->processAll($this->rss->parse($xml)))
            ->then(
                function (int $queued): int {
                    $this->log->info('проход завершён', ['поставлено' => $queued]);

                    return $queued;
                },
                function (Throwable $e): int {
                    $this->log->error('проход не выполнен', ['ошибка' => $e->getMessage()]);

                    return 0;
                },
            );
    }

    /**
     * @param array $items
     * @param int $index
     * @param int $queued
     * @return PromiseInterface<int>
     * @throws DateMalformedStringException
     */
    private function processAll(array $items, int $index = 0, int $queued = 0): PromiseInterface
    {
        if (!isset($items[$index])) {
            return resolve($queued);
        }

        return $this->processItem($items[$index])->then(
            fn(bool $wasQueued): PromiseInterface
                => $this->processAll(
                $items,
                $index + 1,
                $queued + ($wasQueued ? 1 : 0),
            ),
            function (Throwable $e) use ($items, $index, $queued): PromiseInterface {
                // Протухшая сессия: остальные эпизоды упрутся в то же - прерываем весь проход.
                if ($e instanceof SessionExpiredException) {
                    return $this->alertSessionExpired($e)->then(static fn(): int => $queued);
                }

                // Ретраев внутри цепочки нет: URL подписан `ts=`, повтор с середины бессмысленен.
                // Эпизод в `episodes` не пишется - на следующем проходе цепочка начнётся заново.
                $this->log->warn('эпизод пропущен', ['ошибка' => $e->getMessage()]);

                return $this->processAll($items, $index + 1, $queued);
            },
        );
    }

    /**
     * @param FeedItem $item
     * @return PromiseInterface<bool>
     * @throws DateMalformedStringException
     */
    private function processItem(FeedItem $item): PromiseInterface
    {
        $ref = EpisodeRef::tryFrom($item);

        if ($ref === null) {
            $this->log->info('пропуск: не эпизод', ['заголовок' => $item->title]);

            return resolve(false);
        }

        $title = SeriesTitle::fromFeedTitle($item->title)
            ?? new SeriesTitle((string)$ref->seriesId, '', null);

        $this->series->upsert($ref->seriesId, $title->ru, $title->en, $item->posterPath);

        if (!$this->series->isActive($ref->seriesId)) {
            $this->log->info('пропуск: сериал отключён', ['series' => $ref->seriesId]);

            return resolve(false);
        }

        if ($this->episodes->has($ref, $this->quality)) {
            return resolve(false);
        }

        try {
            $search = $this->lostfilm->resolveSearch($ref);
        } catch (Throwable $e) {
            // Провайдер сессии бросает синхронно: нет файла, файл пуст, LF_SESSION не задан.
            // Чинится тем же действием, что и протухший cookie - ведём по той же ветке с защёлкой,
            // иначе самый частый эксплуатационный отказ остался бы виден только в логах.
            return reject(new SessionExpiredException('сессия недоступна: ' . $e->getMessage(), 0, $e));
        }

        return $search
            ->then(function (string $url): string {
                // v_search ответил 200 - сессия жива, защёлку снимаем.
                $this->sessionAlertSent = false;

                return $url;
            })
            ->then(fn(string $url): PromiseInterface => $this->lostfilm->fetchReleasePage($url))
            ->then(function (string $html) use ($ref, $title, $item): PromiseInterface {
                $option = $this->releases->pick($this->releases->parse($html), $this->quality);

                if ($option === null) {
                    // В `episodes` не пишем: элемент выпадет из ленты за сутки-двое и попытки прекратятся.
                    $this->log->warn('пропуск: нужного качества нет', [
                        'series'  => $ref->seriesId,
                        'season'  => $ref->season,
                        'episode' => $ref->episode,
                        'quality' => $this->quality->value,
                    ]);

                    return resolve(false);
                }

                return $this->lostfilm
                    ->fetchTorrent($option->url)
                    ->then(fn(TorrentFile $torrent): bool => $this->store($ref, $title, $item, $torrent));
            });
    }

    /**
     * @param EpisodeRef $ref
     * @param SeriesTitle $title
     * @param FeedItem $item
     * @param TorrentFile $torrent
     * @return bool
     * @throws DateMalformedStringException
     * @throws Throwable
     */
    private function store(EpisodeRef $ref, SeriesTitle $title, FeedItem $item, TorrentFile $torrent): bool
    {
        $this->db->transaction(function () use ($ref, $title, $item, $torrent): void {
            $this->episodes->insert(
                $ref,
                $this->quality,
                $title->episodeTitle ?? '',
                $item->pubDate,
                $torrent->name,
            );
            $this->jobs->enqueue($ref, $this->quality, $torrent->name, $torrent->bytes);
        });

        $this->log->info('эпизод поставлен в очередь', [
            'series' => $ref->seriesId,
            'season' => $ref->season,
            'episode' => $ref->episode,
            'torrent' => $torrent->name,
        ]);

        // Уведомление уходит после фиксации транзакции и намеренно не блокирует проход.
        $this->notifier
            ->notify($this->announcement($ref, $title), $this->posterUrl($item))
            ->then(null, function (Throwable $e): void {
                $this->log->warn('уведомление не ушло', ['ошибка' => $e->getMessage()]);
            });

        return true;
    }

    /**
     * @param EpisodeRef $ref
     * @param SeriesTitle $title
     * @return string
     */
    private function announcement(EpisodeRef $ref, SeriesTitle $title): string
    {
        $head = sprintf('%s (%s) S%02dE%02d', $title->ru, $title->en, $ref->season, $ref->episode);
        $episodeTitle = $title->episodeTitle === null ? '' : "\n" . $title->episodeTitle;

        return "Вышла новая серия\n{$head}{$episodeTitle}\nКачество: {$this->quality->value}";
    }

    private function posterUrl(FeedItem $item): ?string
    {
        return $item->posterPath === '' ? null : "{$this->posterBase}{$item->posterPath}";
    }

    /**
     * @param SessionExpiredException $e
     * @return PromiseInterface<null>
     * @throws DateMalformedStringException
     */
    private function alertSessionExpired(SessionExpiredException $e): PromiseInterface
    {
        $this->log->error('сессия недоступна, проход прерван', ['ошибка' => $e->getMessage()]);

        if ($this->sessionAlertSent) {
            return resolve(null);
        }

        $this->sessionAlertSent = true;

        return $this->notifier
            ->notify(
                "Сессия lostfilm недоступна: v_search отвечает редиректом либо cookie не читается.\n"
                . 'Обновите lf_session - файл LF_SESSION_FILE перечитывается без перезапуска',
            )
            ->then(null, function (Throwable $error): void {
                $this->log->warn('уведомление не ушло', ['ошибка' => $error->getMessage()]);
            });
    }
}
