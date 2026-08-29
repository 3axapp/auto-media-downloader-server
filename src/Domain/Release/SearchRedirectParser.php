<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Domain\Release;

/**
 * Ответ `v_search.php` содержит и `<meta http-equiv="refresh">`, и `location.replace()`.
 * Основной источник - meta, второй дублирует редирект через js.
 *
 * Путь возвращается относительным (`/V/?…`), хост подставляет вызывающий.
 */
final class SearchRedirectParser
{

    private const string PATTERN_META = '~<meta[^>]+http-equiv=["\']refresh["\'][^>]*content=["\'][^"\']*url=([^"\']+)["\']~i';
    private const string PATTERN_JS = '~location\.replace\(\s*["\']([^"\']+)["\']\s*\)~i';

    public function parse(string $html): ?string
    {
        if (preg_match(self::PATTERN_META, $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match(self::PATTERN_JS, $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }
}
