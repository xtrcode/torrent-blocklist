<?php

declare(strict_types=1);

namespace Blocklist\Tests\Writer;

use Blocklist\Ip\RangeSet;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use Blocklist\Writer\P2bWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(P2bWriter::class)]
final class P2bWriterTest extends TestCase
{
    use TempDirectoryTrait;

    public function testVersion3LayoutWithLabelTableAndBigEndianFields(): void
    {
        $dir = $this->createTempDirectory();
        $set = new RangeSet();
        $set->add(Helpers::range('1.2.3.4', '1.2.3.9', 'Alpha'));
        $set->add(Helpers::range('10.0.0.0', '10.255.255.255', 'Beta'));
        $set->add(Helpers::range('200.0.0.0', '200.0.0.255', 'Alpha'));
        $set->add(Helpers::range('2001:db8::', '2001:db8::ff', 'v6'));

        $result = (new P2bWriter(3))->write("$dir/out.p2b", $set->ranges(), ['ignored']);

        self::assertSame(3, $result->written);
        self::assertSame(1, $result->omitted, 'IPv6 cannot be stored in P2B');
        $binary = Helpers::read("$dir/out.p2b");
        self::assertSame("\xFF\xFF\xFF\xFFP2B\x03", substr($binary, 0, 8));

        $pos = 8;
        $nameCount = self::uint32($binary, $pos);
        self::assertSame(2, $nameCount, 'labels are deduplicated');
        $names = [];
        for ($i = 0; $i < $nameCount; ++$i) {
            $nul = strpos($binary, "\0", $pos);
            self::assertIsInt($nul);
            $names[] = substr($binary, $pos, $nul - $pos);
            $pos = $nul + 1;
        }
        self::assertSame(['Alpha', 'Beta'], $names);

        $rangeCount = self::uint32($binary, $pos);
        self::assertSame(3, $rangeCount);
        $ranges = [];
        for ($i = 0; $i < $rangeCount; ++$i) {
            $name = self::uint32($binary, $pos);
            $start = long2ip(self::uint32($binary, $pos));
            $end = long2ip(self::uint32($binary, $pos));
            $ranges[] = $names[$name]." $start-$end";
        }
        self::assertSame(['Alpha 1.2.3.4-1.2.3.9', 'Beta 10.0.0.0-10.255.255.255', 'Alpha 200.0.0.0-200.0.0.255'], $ranges);
        self::assertSame(\strlen($binary), $pos, 'no trailing bytes');
    }

    public function testVersion2And1UseInlineLabels(): void
    {
        $dir = $this->createTempDirectory();
        $set = new RangeSet();
        $set->add(Helpers::range('1.2.3.4', '1.2.3.9', 'Zoë'));

        (new P2bWriter(2))->write("$dir/v2.p2b", $set->ranges(), []);
        self::assertSame("\xFF\xFF\xFF\xFFP2B\x02Zoë\0\x01\x02\x03\x04\x01\x02\x03\x09", Helpers::read("$dir/v2.p2b"));

        (new P2bWriter(1))->write("$dir/v1.p2b", $set->ranges(), []);
        self::assertSame("\xFF\xFF\xFF\xFFP2B\x01Zo\xEB\0\x01\x02\x03\x04\x01\x02\x03\x09", Helpers::read("$dir/v1.p2b"), 'v1 labels are ISO-8859-1');
    }

    public function testRejectsUnknownVersions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new P2bWriter(4);
    }

    private static function uint32(string $binary, int &$pos): int
    {
        $value = unpack('N', $binary, $pos);
        self::assertIsArray($value);
        $pos += 4;
        $number = $value[1];
        self::assertIsInt($number);

        return $number;
    }
}
