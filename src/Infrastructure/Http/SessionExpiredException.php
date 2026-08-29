<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Http;

use RuntimeException;

/**
 * `v_search.php` ответил 302 - cookie `lf_session` больше не действует.
 */
final class SessionExpiredException extends RuntimeException {}
