<?php

declare(strict_types=1);

namespace Blocklist\Report;

final readonly class SkippedSource
{
    public function __construct(
        public string $kind,
        public string $source,
        public string $error,
    ) {
    }
}
