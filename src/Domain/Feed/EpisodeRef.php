<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Feed;

/**
 * Число в пути постера `/Static/Images/<N>/Posters/` равно id сериала.
 * Фильмы отсеиваются здесь же — как естественное следствие отсутствия `SxxEyy`
 * в заголовке, а не отдельным флагом.
 */
final readonly class EpisodeRef
{
    public static function tryFrom(FeedItem $item): ?self
    {
        if (preg_match('~/Static/Images/(\d+)/~', $item->posterPath, $poster) !== 1) {
            return null;
        }

        if (preg_match('~\(S(\d+)E(\d+)\)~u', $item->title, $se) !== 1) {
            return null;
        }

        return new self((int)$poster[1], (int)$se[1], (int)$se[2]);
    }

    public function __construct(
        public int $seriesId,
        public int $season,
        public int $episode,
    ) {}
}
