<?php

declare(strict_types=1);

namespace Blocklist\Tests;

use Blocklist\Config;
use Blocklist\Generator;
use Blocklist\Http\FetchStatus;
use Blocklist\Logger;
use Blocklist\Report\BuildReport;
use Blocklist\Report\CountryReport;
use Blocklist\Report\OutputFile;
use Blocklist\Report\SkippedSource;
use Blocklist\Report\SourceReport;
use Blocklist\Report\SummaryPrinter;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use Blocklist\Tests\Support\TestHttpServer;
use Blocklist\Writer\ManifestWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Generator::class)]
#[CoversClass(BuildReport::class)]
#[CoversClass(SummaryPrinter::class)]
#[CoversClass(ManifestWriter::class)]
#[CoversClass(SourceReport::class)]
#[CoversClass(CountryReport::class)]
#[CoversClass(SkippedSource::class)]
#[CoversClass(OutputFile::class)]
final class GeneratorTest extends TestCase
{
    use TempDirectoryTrait;

    private const array FILES = ['test.p2p', 'test.dat', 'test.p2b', 'test.json', 'test.p2p.gz', 'test.dat.gz', 'test.p2b.gz'];

    public function testBuildsAllFormatsFromLocalSourcesDeterministically(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/base.p2p", "# base\nOrg A:1.0.0.0-1.0.0.255\nOrg B:1.0.1.0-1.0.1.255\nOrg C:9.9.9.9-9.9.9.9\nV6:2001:db8::-2001:db8::ff\n");
        file_put_contents("$dir/extra.zone", "1.0.0.128/25\n10.0.0.0/8\n");
        $env = [
            'BLOCKLIST_SOURCES' => "$dir/base.p2p,$dir/extra.zone",
            'CACHE_DIR' => "$dir/cache",
            'OUTPUT_DIR' => "$dir/out",
            'OUTPUT_NAME' => 'test',
            'OUTPUT_GZIP' => 'true',
        ];

        [$exit, $summary, $log] = $this->generate($env);

        self::assertSame(0, $exit, $log);
        self::assertSame('', $log, 'no warnings or errors');
        self::assertStringContainsString('Total: 4 ranges (IPv4: 3, IPv6: 1)', $summary);
        foreach (self::FILES as $file) {
            self::assertFileExists("$dir/out/$file");
        }

        // Org A + Org B are adjacent and the /25 overlaps: merged into one range labelled "Org A".
        $expected = ['1.0.0.0-1.0.1.255 Org A', '9.9.9.9-9.9.9.9 Org C', '10.0.0.0-10.255.255.255 extra.zone', '2001:db8::-2001:db8::ff V6'];
        self::assertSame($expected, Helpers::readFile("$dir/out/test.p2p"));
        self::assertSame($expected, Helpers::readFile("$dir/out/test.dat"));
        self::assertSame($expected, Helpers::readFile("$dir/out/test.p2p.gz"));

        $manifest = json_decode(Helpers::read("$dir/out/test.json"), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(['total' => 4, 'ipv4' => 3, 'ipv6' => 1], $manifest['ranges']);
        self::assertIsArray($manifest['files']);
        self::assertSame(['bytes' => filesize("$dir/out/test.p2b"), 'sha256' => hash_file('sha256', "$dir/out/test.p2b"), 'ranges' => 3], $manifest['files']['test.p2b'], 'IPv6 omitted from p2b');
        self::assertIsArray($manifest['sources']);
        self::assertCount(2, $manifest['sources']);

        $hashes = $this->hashes("$dir/out");
        [$exit] = $this->generate($env);
        self::assertSame(0, $exit);
        self::assertSame($hashes, $this->hashes("$dir/out"), 'second run is byte-identical');
    }

    public function testFailsOnMissingSourcesUnlessToldToSkipThem(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/base.p2p", "Org A:1.0.0.0-1.0.0.255\n");
        $env = ['BLOCKLIST_SOURCES' => "$dir/base.p2p,$dir/missing.p2p", 'CACHE_DIR' => "$dir/cache", 'OUTPUT_DIR' => "$dir/out"];

        [$exit, , $log] = $this->generate($env);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Local source not found', $log);
        self::assertFileDoesNotExist("$dir/out/blocklist.p2p", 'nothing written on failure');

        [$exit, $summary] = $this->generate($env + ['FAIL_ON_SOURCE_ERROR' => 'false']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('SKIPPED source', $summary);
        self::assertCount(1, Helpers::readFile("$dir/out/blocklist.p2p"));
        $manifest = json_decode(Helpers::read("$dir/out/blocklist.json"), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['skipped']);
        self::assertCount(1, $manifest['skipped']);
    }

    public function testFailsWhenASourceContainsNoRanges(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/empty.p2p", "# nothing here\n");

        [$exit, , $log] = $this->generate(['BLOCKLIST_SOURCES' => "$dir/empty.p2p", 'CACHE_DIR' => "$dir/cache", 'OUTPUT_DIR' => "$dir/out"]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No valid ranges found', $log);
    }

    public function testBlocksCountriesAndCachesTheirZones(): void
    {
        $server = TestHttpServer::start();
        try {
            $server->put('ipblocks/data/aggregated/li-aggregated.zone', "5.0.0.0/24\n5.0.1.0/24\n");
            $server->put('ipv6/ipaddresses/aggregated/li-aggregated.zone', "2001:0678:0bbc:0000:0000:0000:0000:0000/48\n");
            $server->put('ipblocks/data/aggregated/kp-aggregated.zone', "175.45.176.0/22\n");
            $dir = $this->createTempDirectory();
            $env = [
                'BLOCKLIST_SOURCES' => '',
                'BLOCK_COUNTRIES' => 'li,kp',
                'IPDENY_BASE_URL' => $server->baseUrl,
                'CACHE_DIR' => "$dir/cache",
                'OUTPUT_DIR' => "$dir/out",
                'HTTP_RETRIES' => '0',
            ];

            [$exit, $summary, $log, $report] = $this->generate($env);

            self::assertSame(0, $exit, $log);
            self::assertStringContainsString('Country KP: no IPv6 zone on ipdeny.com', $log);
            self::assertStringContainsString('no zone file on ipdeny.com', $summary);
            self::assertSame([
                '5.0.0.0-5.0.1.255 Country LI (ipdeny.com)',
                '175.45.176.0-175.45.179.255 Country KP (ipdeny.com)',
                '2001:678:bbc::-2001:678:bbc:ffff:ffff:ffff:ffff:ffff Country LI (ipdeny.com)',
            ], Helpers::readFile("$dir/out/blocklist.p2p"));
            self::assertSame(
                [FetchStatus::Downloaded, FetchStatus::Downloaded, FetchStatus::Downloaded, null],
                array_map(static fn (CountryReport $c): ?FetchStatus => $c->fetch?->status, $report->countries),
            );
            self::assertFileExists("$dir/cache/127.0.0.1/ipblocks/data/aggregated/li-aggregated.zone");

            $manifest = json_decode(Helpers::read("$dir/out/blocklist.json"), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($manifest);
            self::assertIsArray($manifest['countries']);
            $kp = $manifest['countries']['KP'] ?? null;
            self::assertIsArray($kp);
            self::assertSame(['url' => $server->baseUrl.'/ipv6/ipaddresses/aggregated/kp-aggregated.zone', 'ranges' => 0, 'available' => false], $kp['IPv6'] ?? null);

            // second run: zones are younger than CACHE_TTL, so ipdeny is not contacted at all
            $server->put('ipblocks/data/aggregated/li-aggregated.zone', "6.0.0.0/8\n");
            [$exit, , , $report] = $this->generate($env);
            self::assertSame(0, $exit);
            self::assertSame(
                [FetchStatus::Cached, FetchStatus::Cached, FetchStatus::Cached, null],
                array_map(static fn (CountryReport $c): ?FetchStatus => $c->fetch?->status, $report->countries),
            );
            self::assertStringNotContainsString('6.0.0.0', Helpers::read("$dir/out/blocklist.p2p"));

            // CACHE_TTL=0 forces a revalidation, the changed zone is downloaded
            [$exit, , , $report] = $this->generate(['CACHE_TTL' => '0'] + $env);
            self::assertSame(0, $exit);
            self::assertSame(FetchStatus::Downloaded, $report->countries[0]->fetch?->status);
            self::assertSame(FetchStatus::Revalidated, $report->countries[1]->fetch?->status);
            self::assertStringContainsString('6.0.0.0-6.255.255.255', Helpers::read("$dir/out/blocklist.p2p"));

            // an unknown country code has no zone in any family
            [$exit, , $log] = $this->generate(['BLOCK_COUNTRIES' => 'xx'] + $env);
            self::assertSame(1, $exit);
            self::assertStringContainsString('no zone files for this country code', $log);
        } finally {
            $server->stop();
        }
    }

    public function testNameHintsAndDefaultLabels(): void
    {
        self::assertSame('wael.list.p2p.zip', Generator::nameHint('https://raw.githubusercontent.com/waelisa/Best-blocklist/main/wael.list.p2p.zip?x=1'));
        self::assertSame('wael.list.p2p (raw.githubusercontent.com)', Generator::defaultLabel('https://raw.githubusercontent.com/waelisa/Best-blocklist/main/wael.list.p2p.zip'));
        self::assertSame('custom.p2p', Generator::defaultLabel('/data/custom.p2p'));
        self::assertSame('source', Generator::nameHint('https://example.org/'));
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string, string, BuildReport} exit code, summary, log, report
     */
    private function generate(array $env): array
    {
        $output = Helpers::memoryStream();
        $logStream = Helpers::memoryStream();
        $generator = new Generator(Config::fromEnv($env), new Logger('warn', $logStream), $output);
        $exit = $generator->run();

        return [$exit, Helpers::streamContents($output), Helpers::streamContents($logStream), $generator->report()];
    }

    /**
     * @return array<string, string|false>
     */
    private function hashes(string $dir): array
    {
        $hashes = [];
        foreach (self::FILES as $file) {
            $hashes[$file] = hash_file('sha256', "$dir/$file");
        }

        return $hashes;
    }
}
