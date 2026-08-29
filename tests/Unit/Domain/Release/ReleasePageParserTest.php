<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Unit\Domain\Release;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\QualityLabel;
use Zakharov\AutoMediaDownloaderServer\Domain\Release\ReleasePageParser;
use Zakharov\AutoMediaDownloaderServer\Tests\Support\Fixtures;

final class ReleasePageParserTest extends TestCase {
    #[TestDox('Разбирает три варианта')]
    public function testParsesThreeOptions(): void
    {
        $options = (new ReleasePageParser())->parse(Fixtures::read('release_page.html'));

        self::assertCount(3, $options);
        self::assertSame(
            [QualityLabel::Sd, QualityLabel::Hd1080, QualityLabel::Mp4],
            array_map(static fn ($o) => $o->quality, $options),
        );
    }

    #[TestDox('Метка очищается от пробелов и табуляций')]
    public function testLabelIsTrimmed(): void
    {
        // В живой странице узел метки содержит "\nSD\t\t\t" — сырой `from()` на нём упал бы.
        self::assertSame(QualityLabel::Sd, QualityLabel::tryFromLabel("\nSD\t\t\t"));
        self::assertSame(QualityLabel::Hd1080, QualityLabel::tryFromLabel("\n1080\t\t\t"));
        self::assertNull(QualityLabel::tryFromLabel('1080p'));
    }

    #[TestDox('Берёт ссылку из главного блока, а не из дублирующего')]
    public function testTakesLinkFromMainBlockNotDuplicate(): void
    {
        // В каждом inner-box--item две ссылки: .main и .sub, с РАЗНЫМИ значениями s=.
        $parser = new ReleasePageParser();
        $options = $parser->parse(Fixtures::read('release_page.html'));

        $mp4 = $parser->pick($options, QualityLabel::Mp4);

        self::assertNotNull($mp4);
        self::assertSame('https://n.tracktor.site/td.php?s=REDACTED_SIGNATURE_7', $mp4->url);
        self::assertStringContainsString('720p WEB-DLRip', $mp4->description);
    }

    #[TestDox('Выбор по метке, а не по порядку')]
    public function testPicksByLabelNotByPosition(): void
    {
        $parser = new ReleasePageParser();

        // Порядок переставлен: позиционный выбор из легаси здесь ошибётся.
        $html = <<<'HTML'
            <div class="inner-box--list">
              <div class="inner-box--item">
                <div class="inner-box--label">
            MP4			</div>
                <div class="inner-box--link main"><a href="https://n.tracktor.site/td.php?s=MP4">720p</a></div>
                <div class="inner-box--link sub"><a href="https://n.tracktor.site/td.php?s=DUP">дубль</a></div>
                <div class="inner-box--desc">Видео: 720p WEB-DLRip. Размер: 1.47 ГБ</div>
              </div>
              <div class="inner-box--item">
                <div class="inner-box--label">
            SD			</div>
                <div class="inner-box--link main"><a href="https://n.tracktor.site/td.php?s=SD">WEB-DLRip</a></div>
                <div class="inner-box--desc">Видео: WEB-DLRip. Размер: 704.75 МБ</div>
              </div>
            </div>
            HTML;

        $options = $parser->parse($html);

        self::assertSame('https://n.tracktor.site/td.php?s=SD', $parser->pick($options, QualityLabel::Sd)?->url);
        self::assertSame('https://n.tracktor.site/td.php?s=MP4', $parser->pick($options, QualityLabel::Mp4)?->url);
    }

    #[TestDox('Нужной метки нет на странице')]
    public function testRequestedLabelIsMissing(): void
    {
        $parser = new ReleasePageParser();

        $html = <<<'HTML'
            <div class="inner-box--list">
              <div class="inner-box--item">
                <div class="inner-box--label">SD</div>
                <div class="inner-box--link main"><a href="https://n.tracktor.site/td.php?s=SD">WEB-DLRip</a></div>
                <div class="inner-box--desc">Видео: WEB-DLRip</div>
              </div>
            </div>
            HTML;

        self::assertNull($parser->pick($parser->parse($html), QualityLabel::Hd1080));
    }

    #[TestDox('Страница без вариантов даёт пустой массив')]
    public function testPageWithoutOptionsYieldsEmptyArray(): void
    {
        self::assertSame([], (new ReleasePageParser())->parse('<html><body>Ничего не найдено</body></html>'));
    }

    #[TestDox('Элемент без ссылки пропускается')]
    public function testItemWithoutLinkIsSkipped(): void
    {
        $html = <<<'HTML'
            <div class="inner-box--item">
              <div class="inner-box--label">SD</div>
              <div class="inner-box--desc">Видео: WEB-DLRip</div>
            </div>
            HTML;

        self::assertSame([], (new ReleasePageParser())->parse($html));
    }
}
