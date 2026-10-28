<?php

declare(strict_types=1);

namespace Blocklist\Tests\Writer;

use Blocklist\Ip\RangeSet;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use Blocklist\Writer\DatWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatWriter::class)]
final class DatWriterTest extends TestCase
{
    use TempDirectoryTrait;

    public function testWritesZeroPaddedRangesWithAccessLevelZero(): void
    {
        $dir = $this->createTempDirectory();
        $set = new RangeSet();
        $set->add(Helpers::range('1.2.3.0', '1.2.3.255', 'Org, Inc.'));
        $set->add(Helpers::range('2001:db8::', '2001:db8::ff', 'v6'));

        $result = (new DatWriter())->write("$dir/out.dat", $set->ranges(), ['h']);

        self::assertSame(2, $result->written);
        self::assertSame(
            "# h\n001.002.003.000 - 001.002.003.255 , 000 , Org, Inc.\n2001:db8:: - 2001:db8::ff , 000 , v6\n",
            Helpers::read("$dir/out.dat"),
        );
        self::assertSame(['1.2.3.0-1.2.3.255 Org, Inc.', '2001:db8::-2001:db8::ff v6'], Helpers::readFile("$dir/out.dat"));
    }
}
