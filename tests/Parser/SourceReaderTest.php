<?php

declare(strict_types=1);

namespace Blocklist\Tests\Parser;

use Blocklist\Parser\ParseStats;
use Blocklist\Parser\SourceReader;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceReader::class)]
#[CoversClass(ParseStats::class)]
final class SourceReaderTest extends TestCase
{
    use TempDirectoryTrait;

    public function testPicksParserFromExtensionSkipsCommentsAndCountsMalformedLines(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/list.p2p", "\xEF\xBB\xBF# header\n// also comment\n; and this\n\nA:1.2.3.4-1.2.3.5\r\nbroken line\nB:5.0.0.0-5.0.0.255\n");
        $stats = new ParseStats();

        self::assertSame(['1.2.3.4-1.2.3.5 A', '5.0.0.0-5.0.0.255 B'], Helpers::readFile("$dir/list.p2p", 'x', $stats));
        self::assertSame(7, $stats->lines);
        self::assertSame(2, $stats->ranges);
        self::assertSame(1, $stats->malformed);
        self::assertSame(0, $stats->ignored);
        self::assertSame(['p2p'], $stats->formats);
        self::assertSame(['broken line'], $stats->malformedSamples);
    }

    public function testLimitsMalformedSamples(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/list.p2p", str_repeat("broken\n", 20));
        $stats = new ParseStats();
        Helpers::readFile("$dir/list.p2p", 'x', $stats);

        self::assertSame(20, $stats->malformed);
        self::assertCount(ParseStats::MAX_SAMPLES, $stats->malformedSamples);
    }

    public function testReadsZoneAndDatFiles(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/de.zone", "1.0.0.0/24\n2.0.0.0/16\n");
        self::assertSame(['1.0.0.0-1.0.0.255 Country DE', '2.0.0.0-2.0.255.255 Country DE'], Helpers::readFile("$dir/de.zone", 'Country DE'));

        file_put_contents("$dir/ipfilter.dat", "1.0.0.0 - 1.0.0.255 , 000 , blocked\n2.0.0.0 - 2.0.0.255 , 200 , allowed\n");
        $stats = new ParseStats();
        self::assertSame(['1.0.0.0-1.0.0.255 blocked'], Helpers::readFile("$dir/ipfilter.dat", 'x', $stats));
        self::assertSame(1, $stats->ignored);
    }

    public function testSniffsTheFormatWhenTheExtensionIsUnknown(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/download", "Org:1.2.3.4-1.2.3.5\n");
        $stats = new ParseStats();

        self::assertSame(['1.2.3.4-1.2.3.5 Org'], Helpers::readFile("$dir/download", 'x', $stats));
        self::assertSame(['p2p'], $stats->formats);
    }

    public function testReadsGzipFiles(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/list.p2p.gz", (string) gzencode("Org:1.2.3.4-1.2.3.5\n", 9));
        $stats = new ParseStats();

        self::assertSame(['1.2.3.4-1.2.3.5 Org'], Helpers::readFile("$dir/list.p2p.gz", 'x', $stats));
        self::assertSame(['p2p'], $stats->formats);
    }

    public function testReadsEveryFileInsideAZipArchive(): void
    {
        $dir = $this->createTempDirectory();
        $zip = new \PharData("$dir/bundle.zip");
        $zip->addFromString('wael.list.p2p', "# header\nOrg:1.2.3.4-1.2.3.5\n");
        $zip->addFromString('extra/de.zone', "9.0.0.0/8\n");
        unset($zip);

        $stats = new ParseStats();
        $ranges = Helpers::readFile("$dir/bundle.zip", 'x', $stats);
        sort($ranges);
        self::assertSame(['1.2.3.4-1.2.3.5 Org', '9.0.0.0-9.255.255.255 x'], $ranges);
        self::assertEqualsCanonicalizing(['cidr', 'p2p'], $stats->formats);

        copy("$dir/bundle.zip", "$dir/bundle.bin");
        self::assertCount(2, Helpers::readFile("$dir/bundle.bin"), 'zip detected by magic bytes without .zip extension');
    }

    public function testFailsOnMissingFiles(): void
    {
        $this->expectException(\RuntimeException::class);
        Helpers::readFile('/does/not/exist.p2p');
    }

    public function testRecognisesComments(): void
    {
        self::assertTrue(SourceReader::isComment('# x'));
        self::assertTrue(SourceReader::isComment('// x'));
        self::assertTrue(SourceReader::isComment('; x'));
        self::assertFalse(SourceReader::isComment('/x'));
        self::assertFalse(SourceReader::isComment(''));
    }
}
