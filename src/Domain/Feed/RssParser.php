<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Feed;

use InvalidArgumentException;
use SimpleXMLElement;
use Throwable;

final class RssParser
{
    /**
     * @param string $xml
     * @return list<FeedItem>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = new SimpleXMLElement($xml);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Лента не разбирается как XML: {$e->getMessage()}", 0, $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $items = [];

        foreach ($root->channel->item ?? [] as $node) {
            $items[] = new FeedItem(
                title: trim((string)$node->title),
                posterPath: $this->posterPath((string)$node->description),
                link: trim((string)$node->link),
                pubDate: trim((string)$node->pubDate),
            );
        }

        return $items;
    }

    /**
     * Постер лежит внутри CDATA как HTML-фрагмент `<img src="/Static/Images/1136/Posters/image.jpg" …>`.
     * Второй раз DOM не поднимаем - вытаскиваем регуляркой из строки.
     */
    private function posterPath(string $description): string
    {
        return preg_match('~<img[^>]+src="([^"]+)"~i', $description, $m) === 1 ? $m[1] : '';
    }
}
