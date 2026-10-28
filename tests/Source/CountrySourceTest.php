<?php

declare(strict_types=1);

namespace Blocklist\Tests\Source;

use Blocklist\Source\CountrySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountrySource::class)]
final class CountrySourceTest extends TestCase
{
    public function testAggregatedUrls(): void
    {
        $source = new CountrySource('https://www.ipdeny.com', true);

        self::assertSame('https://www.ipdeny.com/ipblocks/data/aggregated/de-aggregated.zone', $source->ipv4Url('DE'));
        self::assertSame('https://www.ipdeny.com/ipv6/ipaddresses/aggregated/de-aggregated.zone', $source->ipv6Url('de'));
    }

    public function testPlainUrls(): void
    {
        $source = new CountrySource('https://mirror.example', false);

        self::assertSame('https://mirror.example/ipblocks/data/countries/cn.zone', $source->ipv4Url('cn'));
        self::assertSame('https://mirror.example/ipv6/ipaddresses/blocks/cn.zone', $source->ipv6Url('cn'));
    }

    public function testLabel(): void
    {
        self::assertSame('Country DE (ipdeny.com)', CountrySource::label('de'));
    }
}
