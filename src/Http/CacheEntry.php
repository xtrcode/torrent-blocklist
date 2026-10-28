<?php

declare(strict_types=1);

namespace Blocklist\Http;

final readonly class CacheEntry
{
    public function __construct(
        public string $url,
        public string $path,
        public int $fetchedAt,
        public ?string $etag,
        public ?string $lastModified,
    ) {
    }

    public function age(): int
    {
        return max(0, time() - $this->fetchedAt);
    }
}
