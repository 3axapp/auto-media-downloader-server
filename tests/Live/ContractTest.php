<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Live;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Throwable;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\EpisodeRef;
use Zakharov\AutoMediaDownloaderServer\Domain\Feed\RssParser;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\ReleasePageParser;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\SearchRedirectParser;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\LostfilmClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\ReactHttpClient;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Session\ConfigCookieProvider;

#[Group('live')]
final class ContractTest extends TestCase
{
    private LostfilmClient $client;

    protected function setUp(): void
    {
        $session = getenv('LF_SESSION') ?: null;
        $sessionFile = getenv('LF_SESSION_FILE') ?: null;

        if ($session === null && $sessionFile === null) {
            self::markTestSkipped('Нужен живой LF_SESSION или LF_SESSION_FILE');
        }

        $this->client = new LostfilmClient(
            new ReactHttpClient(timeout: 30.0),
            new ConfigCookieProvider($session, $sessionFile),
            new SearchRedirectParser(),
        );
    }

    /** Блокирующее ожидание промиса: в живых тестах event loop наш и завершать его можно. */
    private function await(PromiseInterface $promise): mixed
    {
        $value = null;
        $error = null;

        $promise->then(
            static function ($result) use (&$value): void {
                $value = $result;
            },
            static function (Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        Loop::run();

        if ($error !== null) {
            self::fail('Живой запрос отклонён: ' . $error->getMessage());
        }

        return $value;
    }

    #[TestDox('Лента отдаёт элементы с постерами и номерами серий')]
    public function testFeedYieldsItemsWithPostersAndEpisodeNumbers(): void
    {
        $items = (new RssParser())->parse($this->await($this->client->fetchRss()));

        self::assertGreaterThanOrEqual(10, count($items), 'лента внезапно стала короче');

        $refs = array_filter(array_map(EpisodeRef::tryFrom(...), $items));
        self::assertNotEmpty($refs, 'id сериала больше не извлекается из пути постера');
    }

    #[TestDox('Цепочка резолва доходит до торрента')]
    public function testResolveChainReachesTorrent(): void
    {
        $items = (new RssParser())->parse($this->await($this->client->fetchRss()));
        $ref = null;

        foreach ($items as $item) {
            $ref = EpisodeRef::tryFrom($item);

            if ($ref !== null) {
                break;
            }
        }

        self::assertNotNull($ref, 'в ленте нет ни одного эпизода');

        $releaseUrl = $this->await($this->client->resolveSearch($ref));
        self::assertStringStartsWith('https://www.lostfilm.tv/V/?', $releaseUrl);

        $html = $this->await($this->client->fetchReleasePage($releaseUrl));
        $parser = new ReleasePageParser();
        $options = $parser->parse($html);

        self::assertNotEmpty($options, 'структура inner-box--item изменилась');

        $labels = array_map(static fn ($option): string => $option->quality->value, $options);
        self::assertContains(QualityLabel::Mp4->value, $labels, 'метки качества изменились');

        $option = $parser->pick($options, QualityLabel::Mp4);
        self::assertStringContainsString('n.tracktor.site', $option->url);

        $torrent = $this->await($this->client->fetchTorrent($option->url));
        self::assertStringEndsWith('.torrent', $torrent->name);
        self::assertStringStartsWith('d', $torrent->bytes, 'вместо bencode пришла заглушка');
    }
}
