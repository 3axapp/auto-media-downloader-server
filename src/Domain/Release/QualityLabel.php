<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Release;

/**
 * Метка качества со страницы выбора качества.
 */
enum QualityLabel: string
{
    case Sd = 'SD';
    case Hd1080 = '1080';
    case Mp4 = 'MP4';

    /**
     * Текст метки в живой странице приходит как "\nSD\t\t\t" — обрезаем.
     * @param string $raw
     * @return self|null
     */
    public static function tryFromLabel(string $raw): ?self
    {
        return self::tryFrom(trim($raw));
    }
}
