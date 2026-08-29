<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Infrastructure\Http;

final readonly class HttpResponse
{
    /**
     * @var array<string, string|string[]>
     */
    public array $headers;

    /**
     * @param int $status
     * @param array<string, string|string[]> $headers
     * @param string $body
     */
    public function __construct(
        public int $status,
        array $headers,
        public string $body,
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = is_array($value) ? implode(', ', $value) : (string)$value;
        }

        $this->headers = $normalized;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
