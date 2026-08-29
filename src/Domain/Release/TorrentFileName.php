<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Release;

final class TorrentFileName
{
    /**
     * Получить имя торрент-файла.
     * Принимает как полную строку заголовка, так и одно его значение.
     * @param string $header
     * @return string|null
     */
    public static function fromContentDisposition(string $header): ?string
    {
        $value = preg_replace('~^\s*Content-Disposition:\s*~i', '', trim($header)) ?? '';

        if (preg_match('~(?:^|;|\s)filename\*?=(?:UTF-8\'\')?"?([^";]+)"?~i', $value, $m) !== 1) {
            return null;
        }

        $name = trim($m[1], " \t\"");

        return $name === '' ? null : $name;
    }
}
