<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer;

use InvalidArgumentException;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;

final readonly class Config
{
    /**
     * @param string|null $lfSession
     * @param string|null $lfSessionFile
     * @param string $telegramToken
     * @param list<string> $telegramChats
     * @param string $apiToken
     * @param QualityLabel $quality
     * @param int $rssPollInterval
     * @param int $leaseTtl
     * @param int $maxAttempts
     * @param string $dbPath
     * @param int $httpPort
     */
    public function __construct(
        public ?string $lfSession,
        public ?string $lfSessionFile,
        public string $telegramToken,
        public array $telegramChats,
        public string $apiToken,
        public QualityLabel $quality,
        public int $rssPollInterval,
        public int $leaseTtl,
        public int $maxAttempts,
        public string $dbPath,
        public int $httpPort,
    ) {}

    /**
     * @param array<string, string|false|null> $env
     * @return self
     */
    public static function fromEnv(array $env): self
    {
        $get = static function (string $key, ?string $default = null) use ($env): ?string {
            $value = $env[$key] ?? null;
            $value = ($value === false || $value === null) ? null : trim((string)$value);

            return ($value === null || $value === '') ? $default : $value;
        };

        $required = static function (string $key) use ($get): string {
            $value = $get($key);

            if ($value === null) {
                throw new InvalidArgumentException("Переменная окружения {$key} обязательна");
            }

            return $value;
        };

        $session = $get('LF_SESSION');
        $sessionFile = $get('LF_SESSION_FILE');

        if ($session === null && $sessionFile === null) {
            throw new InvalidArgumentException('Нужна переменная LF_SESSION либо LF_SESSION_FILE');
        }

        $qualityRaw = $get('QUALITY', QualityLabel::Mp4->value);
        $quality = QualityLabel::tryFromLabel((string)$qualityRaw);

        if ($quality === null) {
            throw new InvalidArgumentException(
                "Недопустимое значение QUALITY: {$qualityRaw}. Допустимы SD, 1080, MP4",
            );
        }

        $chats = array_values(
            array_filter(
                array_map(
                    static fn(string $chat): string => trim($chat),
                    explode(',', (string)$get('TELEGRAM_CHATS', '')),
                ),
                static fn(string $chat): bool => $chat !== '',
            ),
        );

        return new self(
            lfSession: $session,
            lfSessionFile: $sessionFile,
            telegramToken: $required('TELEGRAM_TOKEN'),
            telegramChats: $chats,
            apiToken: $required('API_TOKEN'),
            quality: $quality,
            rssPollInterval: (int)$get('RSS_POLL_INTERVAL', '900'),
            leaseTtl: (int)$get('LEASE_TTL', '600'),
            maxAttempts: (int)$get('MAX_ATTEMPTS', '5'),
            dbPath: (string)$get('DB_PATH', '/data/amd.db'),
            httpPort: (int)$get('HTTP_PORT', '8080'),
        );
    }
}
