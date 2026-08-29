<?php

declare(strict_types=1);

namespace Unit\Infrastructure\Session;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Session\ConfigCookieProvider;

final class ConfigCookieProviderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'lf_session_');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    #[TestDox('Берёт значение из переменной окружения')]
    public function testTakesValueFromEnvironment(): void
    {
        $provider = new ConfigCookieProvider('abc123', null);

        self::assertSame('abc123', $provider->cookie());
    }

    #[TestDox('Файл побеждает, если заданы оба')]
    public function testFileWinsOverEnvironment(): void
    {
        file_put_contents($this->path, "из-файла\n");

        $provider = new ConfigCookieProvider('из-окружения', $this->path);

        self::assertSame('из-файла', $provider->cookie());
    }

    #[TestDox('Перечитывает файл после изменения mtime')]
    public function testRereadsFileAfterMtimeChange(): void
    {
        file_put_contents($this->path, 'старое');
        $provider = new ConfigCookieProvider(null, $this->path);
        self::assertSame('старое', $provider->cookie());

        // Замена протухшего cookie не должна требовать перезапуска контейнера.
        file_put_contents($this->path, 'новое');
        touch($this->path, time() + 5);

        self::assertSame('новое', $provider->cookie());
    }

    #[TestDox('Без значения и файла бросает исключение')]
    public function testWithoutValueAndFileThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new ConfigCookieProvider(null, null))->cookie();
    }

    #[TestDox('Пустой файл бросает исключение')]
    public function testEmptyFileThrows(): void
    {
        file_put_contents($this->path, "  \n");

        $this->expectException(RuntimeException::class);

        (new ConfigCookieProvider(null, $this->path))->cookie();
    }
}
