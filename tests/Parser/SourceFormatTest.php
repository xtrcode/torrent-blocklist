<?php

declare(strict_types=1);

namespace Blocklist\Tests\Parser;

use Blocklist\Parser\CidrParser;
use Blocklist\Parser\DatParser;
use Blocklist\Parser\P2pParser;
use Blocklist\Parser\SourceFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceFormat::class)]
final class SourceFormatTest extends TestCase
{
    public function testDetectsFormatFromFileName(): void
    {
        self::assertSame(SourceFormat::P2p, SourceFormat::fromFileName('wael.list.p2p'));
        self::assertSame(SourceFormat::Dat, SourceFormat::fromFileName('ipfilter.DAT'));
        self::assertSame(SourceFormat::Cidr, SourceFormat::fromFileName('de-aggregated.zone'));
        self::assertSame(SourceFormat::Cidr, SourceFormat::fromFileName('firehol_level1.netset'));
        self::assertNull(SourceFormat::fromFileName('download'));
        self::assertNull(SourceFormat::fromFileName('list.txt'));
    }

    public function testSniffsFormatFromContent(): void
    {
        self::assertSame(SourceFormat::P2p, SourceFormat::sniff(['# c', 'Org:1.2.3.4-1.2.3.5', 'Other:2.0.0.0-2.0.0.1']));
        self::assertSame(SourceFormat::Dat, SourceFormat::sniff(['001.002.003.000 - 001.002.003.255 , 000 , x']));
        self::assertSame(SourceFormat::Cidr, SourceFormat::sniff(['1.2.3.0/24', '2001:db8::/32', '1.2.3.4', '1.2.3.4-1.2.3.9']));
        self::assertSame(SourceFormat::Cidr, SourceFormat::sniff(['# only comments']), 'fallback');
        self::assertSame(SourceFormat::Cidr, SourceFormat::sniff([]), 'fallback');
    }

    public function testProvidesTheMatchingParser(): void
    {
        self::assertInstanceOf(P2pParser::class, SourceFormat::P2p->parser());
        self::assertInstanceOf(DatParser::class, SourceFormat::Dat->parser());
        self::assertInstanceOf(CidrParser::class, SourceFormat::Cidr->parser());
    }
}
