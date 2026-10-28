<?php

declare(strict_types=1);

namespace Blocklist\Report;

use Blocklist\Http\FetchResult;
use Blocklist\Parser\ParseStats;

final readonly class SourceReport
{
    public function __construct(
        public string $source,
        public FetchResult $fetch,
        public ParseStats $stats,
    ) {
    }
}
