<?php

declare(strict_types=1);

namespace Blocklist\Report;

use Blocklist\Http\FetchResult;
use Blocklist\Parser\ParseStats;

final readonly class CountryReport
{
    /**
     * @param string $family "IPv4" or "IPv6"
     */
    public function __construct(
        public string $country,
        public string $family,
        public string $url,
        public ?FetchResult $fetch,
        public ?ParseStats $stats,
    ) {
    }

    public static function unavailable(string $country, string $family, string $url): self
    {
        return new self($country, $family, $url, null, null);
    }

    public function isAvailable(): bool
    {
        return null !== $this->stats;
    }
}
