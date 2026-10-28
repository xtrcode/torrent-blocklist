<?php

declare(strict_types=1);

namespace Blocklist\Http;

final readonly class HttpResponse
{
    /**
     * @param bool                  $ok      false on transport errors (DNS, TLS, timeout, ...)
     * @param array<string, string> $headers lower-cased header names of the final response
     */
    public function __construct(
        public bool $ok,
        public int $code,
        public array $headers,
        public string $error,
    ) {
    }

    public function isRetryable(): bool
    {
        return !$this->ok || $this->code >= 500 || 429 === $this->code || 408 === $this->code;
    }
}
