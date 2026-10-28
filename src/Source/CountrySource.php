<?php

declare(strict_types=1);

namespace Blocklist\Source;

/**
 * Builds the ipdeny.com zone file URLs for a country.
 *
 * IPv4: /ipblocks/data/countries/<cc>.zone or /ipblocks/data/aggregated/<cc>-aggregated.zone
 * IPv6: /ipv6/ipaddresses/blocks/<cc>.zone or /ipv6/ipaddresses/aggregated/<cc>-aggregated.zone
 */
final class CountrySource
{
    public function __construct(private readonly string $baseUrl, private readonly bool $aggregated)
    {
    }

    public function ipv4Url(string $country): string
    {
        $cc = strtolower($country);

        return $this->aggregated
            ? "{$this->baseUrl}/ipblocks/data/aggregated/{$cc}-aggregated.zone"
            : "{$this->baseUrl}/ipblocks/data/countries/{$cc}.zone";
    }

    public function ipv6Url(string $country): string
    {
        $cc = strtolower($country);

        return $this->aggregated
            ? "{$this->baseUrl}/ipv6/ipaddresses/aggregated/{$cc}-aggregated.zone"
            : "{$this->baseUrl}/ipv6/ipaddresses/blocks/{$cc}.zone";
    }

    public static function label(string $country): string
    {
        return 'Country '.strtoupper($country).' (ipdeny.com)';
    }
}
