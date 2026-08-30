<?php

declare(strict_types=1);

namespace Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Config;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;

final class ConfigTest extends TestCase
{
    /** @return array<string, string> */
    private function env(array $overrides = []): array
    {
        return $overrides + [
            'LF_SESSION' => 'session-value',
            'TELEGRAM_TOKEN' => 'токен-бота',
            'TELEGRAM_CHATS' => '100500, 100501',
            'API_TOKEN' => 'api-токен',
            'QUALITY' => 'MP4',
        ];
    }

    #[TestDox('Умолчания совпадают со спекой')]
    public function testDefaultsMatchSpec(): void
    {
        $config = Config::fromEnv($this->env());

        self::assertSame(900, $config->rssPollInterval);
        self::assertSame(600, $config->leaseTtl);
        self::assertSame(5, $config->maxAttempts);
        self::assertSame('/data/amd.db', $config->dbPath);
        self::assertSame(8080, $config->httpPort);
        self::assertSame(QualityLabel::Mp4, $config->quality);
        self::assertSame(['100500', '100501'], $config->telegramChats);
    }

    #[TestDox('Значения перечитываются из окружения')]
    public function testValuesComeFromEnvironment(): void
    {
        $config = Config::fromEnv($this->env([
            'QUALITY' => '1080',
            'RSS_POLL_INTERVAL' => '300',
            'LEASE_TTL' => '60',
            'MAX_ATTEMPTS' => '2',
            'DB_PATH' => '/tmp/amd.db',
            'HTTP_PORT' => '9090',
        ]));

        self::assertSame(QualityLabel::Hd1080, $config->quality);
        self::assertSame(300, $config->rssPollInterval);
        self::assertSame(60, $config->leaseTtl);
        self::assertSame(2, $config->maxAttempts);
        self::assertSame('/tmp/amd.db', $config->dbPath);
        self::assertSame(9090, $config->httpPort);
    }

    #[TestDox('Неверное качество отвергается')]
    public function testInvalidQualityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::fromEnv($this->env(['QUALITY' => '1080p']));
    }

    #[TestDox('Обязательные переменные проверяются')]
    public function testRequiredVariablesAreChecked(): void
    {
        foreach (['TELEGRAM_TOKEN', 'API_TOKEN'] as $key) {
            $env = $this->env();
            unset($env[$key]);

            try {
                Config::fromEnv($env);
                self::fail("Отсутствие {$key} должно приводить к исключению");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    #[TestDox('Сессия может быть задана файлом')]
    public function testSessionMayBeGivenByFile(): void
    {
        $env = $this->env(['LF_SESSION_FILE' => '/data/lf_session']);
        unset($env['LF_SESSION']);

        $config = Config::fromEnv($env);

        self::assertNull($config->lfSession);
        self::assertSame('/data/lf_session', $config->lfSessionFile);
    }

    #[TestDox('Без сессии вообще конфиг невалиден')]
    public function testWithoutAnySessionConfigIsInvalid(): void
    {
        $env = $this->env();
        unset($env['LF_SESSION']);

        $this->expectException(InvalidArgumentException::class);

        Config::fromEnv($env);
    }
}
