<?php

declare(strict_types=1);

namespace Blocklist\Tests\Ip;

use Blocklist\Ip\IpMath;
use Blocklist\Tests\Support\Helpers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpMath::class)]
final class IpMathTest extends TestCase
{
    public function testParsesDottedQuadsIncludingZeroPaddedEmuleStyle(): void
    {
        self::assertSame([IpMath::V4, "\x01\x02\x03\x04"], IpMath::parse('1.2.3.4'));
        self::assertSame([IpMath::V4, "\x01\x02\x03\x04"], IpMath::parse('001.002.003.004'));
        self::assertSame([IpMath::V4, "\xFF\xFF\xFF\xFF"], IpMath::parse(' 255.255.255.255 '));
        self::assertSame('1.2.3.4', IpMath::format("\x01\x02\x03\x04"));
        self::assertSame('001.002.003.004', IpMath::formatPadded("\x01\x02\x03\x04"));
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidAddresses(string $address): void
    {
        self::assertNull(IpMath::parse($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        foreach (['', '1.2.3', '1.2.3.256', '1.2.3.4.5', 'abc', '1.2.3.4-', '::g', '1.2.3.4/24'] as $address) {
            yield "\"$address\"" => [$address];
        }
    }

    public function testParsesAndFormatsIpv6(): void
    {
        $parsed = IpMath::parse('2001:0db8:0000:0000:0000:0000:0000:0001');
        self::assertNotNull($parsed);
        self::assertSame(IpMath::V6, $parsed[0]);
        self::assertSame(16, \strlen($parsed[1]));
        self::assertSame('2001:db8::1', IpMath::format($parsed[1]));
        self::assertSame('::ffff:1.2.3.4', IpMath::format(Helpers::ip('::ffff:1.2.3.4')));
    }

    public function testFormatRejectsInvalidBinaryAddresses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IpMath::format('abc');
    }

    public function testFormatPaddedRejectsIpv6(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IpMath::formatPadded(Helpers::ip('2001:db8::'));
    }

    #[DataProvider('cidrBlocks')]
    public function testConvertsCidrBlocksToRanges(string $cidr, string $first, string $last): void
    {
        $range = IpMath::cidrToRange($cidr);
        self::assertNotNull($range);
        self::assertSame([$first, $last], [IpMath::format($range[1]), IpMath::format($range[2])]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cidrBlocks(): iterable
    {
        yield '/8' => ['10.0.0.0/8', '10.0.0.0', '10.255.255.255'];
        yield '/32' => ['192.168.1.77/32', '192.168.1.77', '192.168.1.77'];
        yield '/0' => ['0.0.0.0/0', '0.0.0.0', '255.255.255.255'];
        yield 'odd prefix' => ['2.28.0.0/14', '2.28.0.0', '2.31.255.255'];
        yield 'host bits are cleared' => ['10.1.2.3/16', '10.1.0.0', '10.1.255.255'];
        yield 'no prefix = single host' => ['1.2.3.4', '1.2.3.4', '1.2.3.4'];
        yield 'ipdeny IPv6 notation' => ['2001:0608:0000:0000:0000:0000:0000:0000/32', '2001:608::', '2001:608:ffff:ffff:ffff:ffff:ffff:ffff'];
        yield 'IPv6 /29' => ['2001:db8::/29', '2001:db8::', '2001:dbf:ffff:ffff:ffff:ffff:ffff:ffff'];
    }

    #[DataProvider('invalidCidrBlocks')]
    public function testRejectsInvalidCidrBlocks(string $cidr): void
    {
        self::assertNull(IpMath::cidrToRange($cidr));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCidrBlocks(): iterable
    {
        foreach (['1.2.3.0/33', '1.2.3.0/x', '1.2.3.0/-1', '2001:db8::/129', 'nope/8'] as $cidr) {
            yield $cidr => [$cidr];
        }
    }

    public function testIncrementsAddressesWithCarry(): void
    {
        self::assertSame(Helpers::ip('1.2.4.0'), IpMath::increment(Helpers::ip('1.2.3.255')));
        self::assertSame(Helpers::ip('2.0.0.0'), IpMath::increment(Helpers::ip('1.255.255.255')));
        self::assertNull(IpMath::increment(Helpers::ip('255.255.255.255')));
        self::assertSame(Helpers::ip('2001:db8::1:0'), IpMath::increment(Helpers::ip('2001:db8::ffff')));
    }
}
