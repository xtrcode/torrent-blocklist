<?php

declare(strict_types=1);

namespace Blocklist\Tests\Parser;

use Blocklist\Ip\IpRange;
use Blocklist\Parser\DatParser;
use Blocklist\Parser\LineStatus;
use Blocklist\Tests\Support\Helpers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatParser::class)]
final class DatParserTest extends TestCase
{
    #[DataProvider('validLines')]
    public function testParsesValidLines(string $line, string $expected): void
    {
        $range = (new DatParser())->parseLine($line, 'default');
        self::assertInstanceOf(IpRange::class, $range);
        self::assertSame([$expected], Helpers::describe([$range]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validLines(): iterable
    {
        yield 'zero padded' => ['001.002.003.000 - 001.002.003.255 , 000 , Some organization', '1.2.3.0-1.2.3.255 Some organization'];
        yield 'range only' => ['1.2.3.0-1.2.3.255', '1.2.3.0-1.2.3.255 default'];
        yield 'access 127 still blocks' => ['1.2.3.0 - 1.2.3.255 , 127 , edge', '1.2.3.0-1.2.3.255 edge'];
        yield 'label with comma' => ['1.2.3.0 - 1.2.3.255 , 0 , with, comma', '1.2.3.0-1.2.3.255 with, comma'];
        yield 'IPv6' => ['2001:db8:: - 2001:db8::ff , 000 , v6', '2001:db8::-2001:db8::ff v6'];
    }

    public function testIgnoresRangesWithAccessLevelAbove127(): void
    {
        $parser = new DatParser();
        self::assertSame(LineStatus::Ignored, $parser->parseLine('1.2.3.0 - 1.2.3.255 , 128 , allowed', 'd'));
        self::assertSame(LineStatus::Ignored, $parser->parseLine('1.2.3.0 - 1.2.3.255 , 255 , allowed', 'd'));
    }

    #[DataProvider('malformedLines')]
    public function testRejectsMalformedLines(string $line): void
    {
        self::assertSame(LineStatus::Malformed, (new DatParser())->parseLine($line, 'default'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLines(): iterable
    {
        yield 'no dash' => ['1.2.3.0 , 000 , no dash'];
        yield 'mixed families' => ['1.2.3.0 - 2001:db8:: , 000 , mixed'];
        yield 'non numeric access' => ['1.2.3.0 - 1.2.3.255 , abc , x'];
        yield 'garbage' => ['hello world'];
    }
}
