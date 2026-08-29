<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Session;

use RuntimeException;

/**
 * Cookie берётся из LF_SESSION либо из файла (LF_SESSION_FILE),
 * который перечитывается по mtime. Замена протухшего cookie - самая частая
 * эксплуатационная операция, и она не должна требовать перезапуска контейнера.
 */
final class ConfigCookieProvider implements SessionProvider
{
    private ?string $cached = null;
    private ?int $cachedMtime = null;

    public function __construct(
        private readonly ?string $value,
        private readonly ?string $file,
    ) {}

    public function cookie(): string
    {
        if ($this->file !== null && $this->file !== '') {
            return $this->fromFile($this->file);
        }

        $value = trim((string)$this->value);

        if ($value === '') {
            throw new RuntimeException('Сессия не задана: заполните LF_SESSION или LF_SESSION_FILE');
        }

        return $value;
    }

    private function fromFile(string $path): string
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        if ($mtime === false) {
            throw new RuntimeException("Файл сессии не читается: {$path}");
        }

        if ($this->cached === null || $mtime !== $this->cachedMtime) {
            $content = trim((string)@file_get_contents($path));

            if ($content === '') {
                throw new RuntimeException("Файл сессии пуст: {$path}");
            }

            $this->cached = $content;
            $this->cachedMtime = $mtime;
        }

        return $this->cached;
    }
}
