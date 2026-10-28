<?php

declare(strict_types=1);

namespace Blocklist\Http;

final class DownloadException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 0)
    {
        parent::__construct($message);
    }

    public function isNotFound(): bool
    {
        return 404 === $this->httpCode;
    }
}
