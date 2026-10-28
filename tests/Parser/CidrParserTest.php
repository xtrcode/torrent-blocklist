<?php

declare(strict_types=1);

namespace Blocklist\Tests\Parser;

use Blocklist\Ip\IpRange;
use Blocklist\Parser\CidrParser;
use Blocklist\Parser\LineStatus;
use Blocklist\Tests\Support\Helpers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CidrParser::class)]
final class CidrParserTest extends TestCase
{
    #[DataProvider('validLines')]
    public function testParsesValidLines(string $line, string $expected): void
    {
        $range = (new CidrParser())->parseLine($line, 'Country DE');
        self::assertInstanceOf(IpRange::class, $range);
        self::assertSame([$expected], Helpers::describe([$range]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validLines(): iterable
    {
        yield 'cidr' => ['2.28.0.0/14', '2.28.0.0-2.31.255.255 Country DE'];
        yield 'single address' => ['1.2.3.4', '1.2.3.4-1.2.3.4 Country DE'];
        yield 'range' => ['1.2.3.4 - 1.2.3.9', '1.2.3.4-1.2.3.9 Country DE'];
        yield 'inline comment' => ['10.0.0.0/24 # office', '10.0.0.0-10.0.0.255 Country DE'];
        yield 'ipdeny IPv6' => ['2001:0608:0000:0000:0000:0000:0000:0000/32', '2001:608::-2001:608:ffff:ffff:ffff:ffff:ffff:ffff Country DE'];
    }

    public function testRejectsMalformedLines(): void
    {
        $parser = new CidrParser();
        self::assertSame(LineStatus::Malformed, $parser->parseLine('garbage', 'x'));
        self::assertSame(LineStatus::Malformed, $parser->parseLine('1.2.3.0/40', 'x'));
        self::assertSame(LineStatus::Malformed, $parser->parseLine('1.2.3.4 - 2001:db8::', 'x'));
    }
}
