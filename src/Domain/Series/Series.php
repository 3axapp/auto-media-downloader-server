<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Series;

final class Series
{
    public function __construct(
        public int $id,
        public string $nameRu,
        public string $nameEn,
        public bool $active,
    ) {}

    /**
     * `Безумцы (Mad Men)` - как сериал называется в ответах бота.
     * Названий может не быть: заголовок ленты не всегда разбирается,
     * и тогда единственное, чем сериал можно назвать, это его id.
     */
    public function label(): string
    {
        if ($this->nameRu === '') {
            return $this->nameEn === '' ? "id {$this->id}" : $this->nameEn;
        }

        return $this->nameEn === '' ? $this->nameRu : "$this->nameRu ({$this->nameEn})";
    }
}
