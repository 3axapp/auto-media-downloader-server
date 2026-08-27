<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Support;

use RuntimeException;

final class Fixtures
{
    public static function read(string $name): string
    {
        $path = self::path($name);
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Фикстура не найдена: {$path}");
        }

        return $content;
    }

    public static function path(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }
}
