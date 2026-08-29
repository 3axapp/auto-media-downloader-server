<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Storage;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\BotStateRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;

final class BotStateRepositoryTest extends TestCase
{
    private BotStateRepository $state;

    protected function setUp(): void
    {
        $db = SqliteConnection::memory();
        $db->migrate();
        $this->state = new BotStateRepository($db);
    }

    #[TestDox('Начальный offset нулевой')]
    public function testInitialOffsetIsZero(): void
    {
        self::assertSame(0, $this->state->offset());
    }

    #[TestDox('Offset сохраняется и перезаписывается')]
    public function testOffsetIsStoredAndOverwritten(): void
    {
        $this->state->setOffset(42);
        self::assertSame(42, $this->state->offset());

        $this->state->setOffset(43);
        self::assertSame(43, $this->state->offset());
    }

    #[TestDox('Произвольный ключ')]
    public function testArbitraryKey(): void
    {
        self::assertNull($this->state->get('нет такого'));

        $this->state->set('ключ', 'значение');

        self::assertSame('значение', $this->state->get('ключ'));
    }
}
