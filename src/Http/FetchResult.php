<?php

declare(strict_types=1);

namespace Blocklist\Http;

use Blocklist\Report\Format;

final readonly class FetchResult
{
    public function __construct(
        public string $path,
        public FetchStatus $status,
        public int $age = 0,
    ) {
    }

    public function describe(): string
    {
        return match ($this->status) {
            FetchStatus::Local => 'local file',
            FetchStatus::Cached => 'from cache (age '.Format::duration($this->age).')',
            FetchStatus::Revalidated => 'from cache (server reports: not modified)',
            FetchStatus::Downloaded => 'downloaded',
            FetchStatus::Stale => 'STALE cache copy (age '.Format::duration($this->age).', download failed)',
        };
    }
}
