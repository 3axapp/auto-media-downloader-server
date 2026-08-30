<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Bot;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Application\Bot\Commands;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\FakeClock;

final class CommandsTest extends TestCase
{
    private SeriesRepository $series;
    private JobRepository $jobs;
    private Commands $commands;

    protected function setUp(): void
    {
        $db = SqliteConnection::memory();
        $db->migrate();

        $clock = new FakeClock(new DateTimeImmutable('2026-08-24T18:40:00+00:00'));
        $this->series = new SeriesRepository($db, $clock);
        $this->series->upsert(1136, 'Безумцы', 'Mad Men', '');
        $this->series->upsert(733, 'Бункер', 'Silo', '');

        $this->jobs = new JobRepository($db, $clock);
        $this->jobs->enqueue(new EpisodeRef(1136, 7, 6), QualityLabel::Mp4, 'Mad.Men.S07E06.torrent', 'd…e');

        $this->commands = new Commands($this->series, $this->jobs, $db);
    }

    #[TestDox('Help резолвится')]
    public function testHelpResolves(): void
    {
        $reply = $this->commands->handle('/help');

        self::assertNotNull($reply);
        self::assertStringContainsString('/list', $reply);
        self::assertStringContainsString('/enable', $reply);
        self::assertStringContainsString('/disable', $reply);
    }

    #[TestDox('List показывает названия и состояние')]
    public function testListShowsNamesAndState(): void
    {
        $this->series->setActive(733, false);

        $reply = $this->commands->handle('/list');

        self::assertNotNull($reply);
        self::assertStringContainsString('Безумцы', $reply);
        self::assertStringContainsString('Бункер', $reply);
    }

    #[TestDox('Disable отключает, а enable включает')]
    public function testDisableTurnsOffAndEnableTurnsOn(): void
    {
        $this->commands->handle('/disable Безумцы');
        self::assertFalse($this->series->isActive(1136));

        // В легаси enableCmd() вызывал disableSeries() — инвертированная логика.
        $this->commands->handle('/enable Безумцы');
        self::assertTrue($this->series->isActive(1136));
    }

    #[TestDox('Команда принимает и id')]
    public function testCommandAlsoAcceptsId(): void
    {
        $this->commands->handle('/disable 733');

        self::assertFalse($this->series->isActive(733));
    }

    #[TestDox('Неизвестный сериал даёт понятный ответ')]
    public function testUnknownSeriesGivesClearReply(): void
    {
        $reply = $this->commands->handle('/disable Вечность');

        self::assertNotNull($reply);
        self::assertStringContainsString('не найден', mb_strtolower($reply));
    }

    #[TestDox('Команда без аргумента даёт подсказку')]
    public function testCommandWithoutArgumentGivesHint(): void
    {
        $reply = $this->commands->handle('/enable');

        self::assertNotNull($reply);
        self::assertStringContainsString('/enable', $reply);
    }

    #[TestDox('Status показывает очередь')]
    public function testStatusShowsQueue(): void
    {
        $reply = $this->commands->handle('/status');

        self::assertNotNull($reply);
        self::assertStringContainsString('1', $reply);
    }

    #[TestDox('Команда с именем бота распознаётся')]
    public function testCommandWithBotNameIsRecognized(): void
    {
        self::assertNotNull($this->commands->handle('/list@amd_bot'));
    }

    #[TestDox('Обычный текст не команда')]
    public function testPlainTextIsNotCommand(): void
    {
        self::assertNull($this->commands->handle('привет'));
    }

    #[TestDox('Неизвестная команда отсылает к help')]
    public function testUnknownCommandPointsToHelp(): void
    {
        $reply = $this->commands->handle('/чтотоне');

        self::assertNotNull($reply);
        self::assertStringContainsString('/help', $reply);
    }

    #[TestDox('Disable снимает незавершённые задания сериала и сообщает их число')]
    public function testDisableRemovesUnfinishedJobsAndReportsCount(): void
    {
        $reply = $this->commands->handle('/disable Безумцы');

        self::assertNotNull($reply);
        self::assertStringContainsString('отменено заданий: 1', $reply);
        self::assertSame(0, $this->jobs->countByStatus(JobStatus::Pending));
    }

    #[TestDox('Переключение слежения называет сериал, а не его id')]
    public function testTogglingNamesTheSeriesInsteadOfItsId(): void
    {
        $disabled = $this->commands->handle('/disable 1136');
        $enabled = $this->commands->handle('/enable Безумцы');

        self::assertNotNull($disabled);
        self::assertNotNull($enabled);
        self::assertStringContainsString('Безумцы (Mad Men)', $disabled);
        self::assertStringContainsString('Безумцы (Mad Men)', $enabled);
        self::assertStringNotContainsString('id 1136', $disabled);
        self::assertStringNotContainsString('id 1136', $enabled);
    }

    #[TestDox('Сериал без названий называется своим id')]
    public function testSeriesWithoutNamesIsCalledByItsId(): void
    {
        // Заголовок ленты не разобрался: в базе есть строка, но названий нет.
        $this->series->upsert(404, '', '', '');

        $reply = $this->commands->handle('/disable 404');

        self::assertNotNull($reply);
        self::assertStringContainsString('id 404', $reply);
    }

    #[TestDox('Disable неизвестного сериала не трогает очередь')]
    public function testDisableOfUnknownSeriesLeavesQueueIntact(): void
    {
        // Задание с series_id, которого нет в `series`: если удалять до проверки
        // существования сериала, оно исчезнет, а ответом всё равно будет «не найден».
        $this->jobs->enqueue(new EpisodeRef(999, 1, 1), QualityLabel::Mp4, 'Ghost.S01E01.torrent', 'd…e');

        $reply = $this->commands->handle('/disable 999');

        self::assertNotNull($reply);
        self::assertStringContainsString('не найден', mb_strtolower($reply));
        self::assertSame(2, $this->jobs->countByStatus(JobStatus::Pending));
    }

    #[TestDox('Enable не удаляет заданий')]
    public function testEnableRemovesNothing(): void
    {
        // В легаси enableCmd() вызывал disableSeries() — инвертированная логика.
        $this->commands->handle('/enable Безумцы');

        self::assertSame(1, $this->jobs->countByStatus(JobStatus::Pending));
    }

}
