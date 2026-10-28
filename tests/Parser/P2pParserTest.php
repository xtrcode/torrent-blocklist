<?php

declare(strict_types=1);

namespace Blocklist\Tests\Parser;

use Blocklist\Ip\IpRange;
use Blocklist\Parser\LineStatus;
use Blocklist\Parser\P2pParser;
use Blocklist\Tests\Support\Helpers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(P2pParser::class)]
final class P2pParserTest extends TestCase
{
    #[DataProvider('validLines')]
    public function testParsesValidLines(string $line, string $expected): void
    {
        $range = (new P2pParser())->parseLine($line, 'default');
        self::assertInstanceOf(IpRange::class, $range);
        self::assertSame([$expected], Helpers::describe([$range]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validLines(): iterable
    {
        yield 'standard' => ['Some Org:1.2.3.0-1.2.3.255', '1.2.3.0-1.2.3.255 Some Org'];
        yield 'from Best-blocklist' => ['Zytron:65.16.218.72-65.16.218.79', '65.16.218.72-65.16.218.79 Zytron'];
        yield 'empty label' => [':1.2.3.4-1.2.3.4', '1.2.3.4-1.2.3.4 default'];
        yield 'label with colon and dash' => ['AS123: Foo-Bar:1.2.3.4-1.2.3.5', '1.2.3.4-1.2.3.5 AS123: Foo-Bar'];
        yield 'label with several colons' => ['abc:def:1.2.3.4-1.2.3.5', '1.2.3.4-1.2.3.5 abc:def'];
        yield 'IPv6 (qBittorrent example)' => ['My:Company:Label:2001:db8::1-2001:db8::ff', '2001:db8::1-2001:db8::ff My:Company:Label'];
    }

    #[DataProvider('malformedLines')]
    public function testRejectsMalformedLines(string $line): void
    {
        self::assertSame(LineStatus::Malformed, (new P2pParser())->parseLine($line, 'default'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLines(): iterable
    {
        foreach (['no range here', 'x:1.2.3.4', 'x:1.2.3.4-2001:db8::1', 'x:1.2.3-1.2.3.4', '1.2.3.4-1.2.3.5'] as $line) {
            yield $line => [$line];
        }
    }
}
