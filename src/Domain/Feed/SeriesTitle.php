<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Feed;

/**
 * Заголовок ленты имеет устойчивый формат `Русское (English). Название эпизода. (SxxEyy)`,
 * у фильмов вместо эпизода стоит `(Фильм)`.
 */
final readonly class SeriesTitle
{
    public static function fromFeedTitle(string $title): ?self
    {
        if (preg_match('~^(?<ru>.+?) \((?<en>.+?)\)\. (?<rest>.+)$~u', trim($title), $m) !== 1) {
            return null;
        }

        // Хвост `. (S07E06)` в название эпизода не входит.
        $rest = preg_replace('~\s*\(S\d+E\d+\)\s*$~u', '', $m['rest']) ?? '';
        $rest = rtrim($rest, ". \t");

        // У фильмов в остатке стоит `(Фильм)` — названия эпизода нет.
        $episodeTitle = ($rest === '' || str_starts_with($rest, '(')) ? null : $rest;

        return new self($m['ru'], $m['en'], $episodeTitle);
    }

    public function __construct(
        public string $ru,
        public string $en,
        public ?string $episodeTitle,
    ) {}
}
