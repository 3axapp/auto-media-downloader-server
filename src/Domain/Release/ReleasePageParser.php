<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Release;

use DOMDocument;
use DOMXPath;

/**
 * Парсер страницы выбора качества.
 */
final class ReleasePageParser
{
    /**
     * Распарсить страницу с выбором качества.
     * @param string $html
     * @return array
     */
    public function parse(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Страница отдаётся в utf-8, но без указания кодировки внутри фрагментов.
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $options = [];

        foreach ($xpath->query('//div[contains(@class, "inner-box--item")]') as $item) {
            $label = $xpath->query('.//div[contains(@class, "inner-box--label")]', $item)->item(0);
            $quality = $label === null ? null : QualityLabel::tryFromLabel($label->textContent);

            // Берём именно .main: рядом лежит .sub с другим значением s=.
            $link = $xpath
                ->query('.//div[contains(@class, "inner-box--link") and contains(@class, "main")]//a', $item)
                ->item(0);

            if ($quality === null || $link === null) {
                continue;
            }

            $description = $xpath->query('.//div[contains(@class, "inner-box--desc")]', $item)->item(0);

            $options[] = new ReleaseOption(
                quality: $quality,
                url: trim($link->getAttribute('href')),
                description: trim($description?->textContent ?? ''),
            );
        }

        return $options;
    }

    /**
     * Выбрать вариант с нужным качеством.
     * @param list<ReleaseOption> $options
     * @param QualityLabel $quality
     * @return ReleaseOption|null
     */
    public function pick(array $options, QualityLabel $quality): ?ReleaseOption
    {
        return array_find($options, fn($option) => $option->quality === $quality);
    }
}
