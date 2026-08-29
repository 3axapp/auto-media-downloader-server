<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Series\Series;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;

final class SeriesRepositoryTest extends TestCase
{
    private SqliteConnection $db;
    private SeriesRepository $series;

    protected function setUp(): void
    {
        $this->db = SqliteConnection::memory();
        $this->db->migrate();
        $this->series = new SeriesRepository(
            $this->db,
            new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00')),
        );
    }

    #[TestDox('Новый сериал активен по умолчанию')]
    public function testNewSeriesIsActiveByDefault(): void
    {
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '/Static/Images/1136/Posters/image.jpg');

        self::assertTrue($this->series->isActive(1136));
    }

    #[TestDox('Повторный upsert обновляет названия и не воскрешает отключённый')]
    public function testUpsertUpdatesNamesWithoutReactivating(): void
    {
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '/Static/Images/1136/Posters/image.jpg');
        $this->series->setActive(1136, false);

        $this->series->upsert(1136, 'Безумцы (новое)', 'Mad Men', '/Static/Images/1136/Posters/other.jpg');

        self::assertFalse($this->series->isActive(1136));
        self::assertSame('Безумцы (новое)', $this->series->all()[0]->nameRu);
    }

    #[TestDox('Неизвестный сериал не считается активным')]
    public function testUnknownSeriesIsNotActive(): void
    {
        self::assertFalse($this->series->isActive(999));
        self::assertFalse($this->series->setActive(999, true));
    }

    #[TestDox('Поиск по подстроке русского и английского названия')]
    public function testFindsBySubstringOfEitherName(): void
    {
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '');
        $this->series->upsert(733, 'Бункер', 'Silo', '');

        self::assertSame(1136, $this->series->findByName('безум')?->id);
        self::assertSame('Бункер (Silo)', $this->series->findByName('SILO')?->label());
        self::assertNull($this->series->findByName('чего-то нет'));
    }

    #[TestDox('Список отсортирован по русскому названию')]
    public function testListIsSortedByRussianName(): void
    {
        $this->series->upsert(733, 'Бункер', 'Silo', '');
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '');

        self::assertSame(
            ['Безумцы', 'Бункер'],
            array_map(static fn(Series $row): string => $row->nameRu, $this->series->all()),
        );
    }

    #[TestDox('Миграция идемпотентна')]
    public function testMigrationIsIdempotent(): void
    {
        $this->db->migrate();
        $this->db->migrate();

        self::assertTrue($this->series->isActive(1136) === false);
    }

    #[TestDox('Поиск по id возвращает сериал целиком')]
    public function testFindByIdReturnsWholeSeries(): void
    {
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '');
        $this->series->setActive(1136, false);

        $found = $this->series->find(1136);

        self::assertNotNull($found);
        self::assertSame(1136, $found->id);
        self::assertSame('Безумцы', $found->nameRu);
        self::assertSame('Mad Men', $found->nameEn);
        self::assertFalse($found->active);
    }

    #[TestDox('Поиск неизвестного id даёт null')]
    public function testFindByUnknownIdYieldsNull(): void
    {
        self::assertNull($this->series->find(999));
    }
}
