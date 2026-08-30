<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Application\Bot;

use Throwable;
use Zakharov\AutoMediaDownloaderServer\Domain\Job\JobStatus;
use Zakharov\AutoMediaDownloaderServer\Domain\Series\Series;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\JobRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SeriesRepository;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Storage\SqliteConnection;

/**
 * Роутинг команд
 */
final readonly class Commands
{

    public function __construct(
        private SeriesRepository $series,
        private JobRepository $jobs,
        private SqliteConnection $db,
    ) {}

    /**
     * @param string $text
     * @return string|null
     * @throws Throwable
     */
    public function handle(string $text): ?string
    {
        $text = trim($text);

        if (!str_starts_with($text, '/')) {
            return null;
        }

        [$command, $argument] = array_pad(explode(' ', $text, 2), 2, '');

        // В группах Telegram присылает `/list@имя_бота`.
        $command = strtolower(explode('@', substr($command, 1), 2)[0]);
        $argument = trim($argument);

        return match ($command) {
            'help', 'start' => $this->help(),
            'list' => $this->list(),
            'enable' => $this->toggle($argument, true),
            'disable' => $this->toggle($argument, false),
            'status' => $this->status(),
            default => "Неизвестная команда. Список доступных — /help",
        };
    }

    private function help(): string
    {
        return "Доступные команды:\n" .
            "/list — список сериалов и их состояние\n" .
            "/enable <название|id> — следить за сериалом\n" .
            "/disable <название|id> — перестать следить и снять незавершённые задания\n" .
            "/status — состояние очереди заданий\n" .
            "/help — эта справка";
    }

    private function list(): string
    {
        $rows = $this->series->all();

        if (!$rows) {
            return 'Список пуст: ни одного сериала ещё не встречалось в ленте.';
        }

        $lines = array_map(
            static fn(Series $row): string
                => sprintf(
                '%s %s, id %d',
                $row->active ? '✅' : '⏸',
                $row->label(),
                $row->id,
            ),
            $rows,
        );

        return implode("\n", $lines);
    }

    /**
     * @param string $argument
     * @param bool $active
     * @return string
     * @throws Throwable
     */
    private function toggle(string $argument, bool $active): string
    {
        if ($argument === '') {
            $name = $active ? '/enable' : '/disable';

            return "Нужен аргумент: {$name} <название|id>";
        }

        $series = ctype_digit($argument)
            ? $this->series->find((int)$argument)
            : $this->series->findByName($argument);

        if (!$series) {
            return "Сериал «{$argument}» не найден";
        }

        $removed = $this->db->transaction(function () use ($series, $active): ?int {
            if (!$this->series->setActive($series->id, $active)) {
                return null;
            }

            return $active ? 0 : $this->jobs->deleteBySeries($series->id);
        });

        if ($removed === null) {
            return "Сериал «{$argument}» не изменил статус";
        }

        if ($active) {
            return "Слежу за сериалом «{$series->label()}»";
        }

        return "Больше не слежу за сериалом «{$series->label()}», "
            . ($removed === 0 ? 'незавершённых заданий не было' : "отменено заданий: {$removed}");
    }

    private function status(): string
    {
        $counts = [];

        foreach (JobStatus::cases() as $status) {
            $counts[] = "{$status->value}: {$this->jobs->countByStatus($status)}";
        }

        return "Очередь заданий\n" . implode("\n", $counts);
    }
}
