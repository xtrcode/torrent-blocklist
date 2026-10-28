<?php

declare(strict_types=1);

namespace Blocklist\Tests\Ip;

use Blocklist\Ip\IpRange;
use Blocklist\Ip\RangeSet;
use Blocklist\Tests\Support\Helpers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RangeSet::class)]
#[CoversClass(IpRange::class)]
final class RangeSetTest extends TestCase
{
    public function testDeduplicatesNormalisesReversedRangesAndSorts(): void
    {
        $set = new RangeSet();
        self::assertTrue($set->add(Helpers::range('10.0.0.0', '10.0.0.255', 'a')));
        self::assertFalse($set->add(Helpers::range('10.0.0.255', '10.0.0.0', 'reversed duplicate')));
        self::assertTrue($set->add(Helpers::range('2001:db8::', '2001:db8::ff', 'v6')));
        self::assertTrue($set->add(Helpers::range('1.0.0.0', '1.0.0.9', 'b')));
        self::assertTrue($set->add(Helpers::range('1.0.0.0', '1.0.0.255', 'wider')));

        self::assertCount(4, $set);
        self::assertSame([4 => 3, 6 => 1], $set->countByFamily());
        self::assertSame(
            ['1.0.0.0-1.0.0.255 wider', '1.0.0.0-1.0.0.9 b', '10.0.0.0-10.0.0.255 a', '2001:db8::-2001:db8::ff v6'],
            Helpers::describe($set->ranges()),
            'sorted by start ascending, then end descending; IPv4 before IPv6',
        );
    }

    public function testMergesOverlappingAndAdjacentRangesAndKeepsTheFirstLabel(): void
    {
        $set = new RangeSet();
        $set->add(Helpers::range('1.0.0.0', '1.0.0.9', 'first'));
        $set->add(Helpers::range('1.0.0.5', '1.0.0.20', 'overlap'));
        $set->add(Helpers::range('1.0.0.21', '1.0.0.30', 'adjacent'));
        $set->add(Helpers::range('1.0.0.32', '1.0.0.40', 'gap'));
        $set->add(Helpers::range('1.0.0.33', '1.0.0.35', 'inside'));
        $set->add(Helpers::range('255.255.255.0', '255.255.255.255', 'top'));
        $set->add(Helpers::range('255.255.255.250', '255.255.255.255', 'top inside'));
        $set->add(Helpers::range('2001:db8::', '2001:db8::ff', 'v6a'));
        $set->add(Helpers::range('2001:db8::100', '2001:db8::1ff', 'v6b'));
        $set->add(Helpers::range('2001:db8::300', '2001:db8::3ff', 'v6c'));

        self::assertSame(5, $set->merge());
        self::assertSame([
            '1.0.0.0-1.0.0.30 first',
            '1.0.0.32-1.0.0.40 gap',
            '255.255.255.0-255.255.255.255 top',
            '2001:db8::-2001:db8::1ff v6a',
            '2001:db8::300-2001:db8::3ff v6c',
        ], Helpers::describe($set->ranges()));
    }

    public function testNeverMergesAcrossAddressFamilies(): void
    {
        $set = new RangeSet();
        $set->add(Helpers::range('255.255.255.0', '255.255.255.255', 'v4'));
        $set->add(Helpers::range('::', '::ff', 'v6'));

        self::assertSame(0, $set->merge());
        self::assertCount(2, $set);
    }

    public function testEmptySet(): void
    {
        $set = new RangeSet();
        self::assertSame(0, $set->merge());
        self::assertSame([], Helpers::describe($set->ranges()));
        self::assertSame([4 => 0, 6 => 0], $set->countByFamily());
    }
}
