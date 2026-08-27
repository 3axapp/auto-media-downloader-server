<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Feed;

/**
 * Один элемент RSS-ленты. Ссылка сохраняется целиком, но в цепочке резолва
 * не используется: она ведёт на "мыльный редиректер" lostfilm.download.
 */
final readonly class FeedItem
{
    public function __construct(
        public string $title,
        public string $posterPath,
        public string $link,
        public string $pubDate,
    ) {}
}
