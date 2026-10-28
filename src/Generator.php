<?php

declare(strict_types=1);

namespace Blocklist;

use Blocklist\Http\Downloader;
use Blocklist\Http\DownloadException;
use Blocklist\Http\FileCache;
use Blocklist\Ip\IpMath;
use Blocklist\Ip\RangeSet;
use Blocklist\Parser\ParseStats;
use Blocklist\Parser\SourceReader;
use Blocklist\Report\BuildReport;
use Blocklist\Report\CountryReport;
use Blocklist\Report\Format;
use Blocklist\Report\OutputFile;
use Blocklist\Report\SkippedSource;
use Blocklist\Report\SourceReport;
use Blocklist\Report\SummaryPrinter;
use Blocklist\Source\CountrySource;
use Blocklist\Writer\DatWriter;
use Blocklist\Writer\ManifestWriter;
use Blocklist\Writer\P2bWriter;
use Blocklist\Writer\P2pWriter;
use Blocklist\Writer\WriterInterface;

/**
 * Orchestrates one build: fetch sources and country zones, collect ranges, merge, write files.
 */
final class Generator
{
    public const string PROJECT_URL = 'https://github.com/xtrcode/torrent-blocklist';

    private readonly Downloader $downloader;
    private readonly SourceReader $reader;
    private readonly CountrySource $countries;
    private BuildReport $report;

    /** @var resource */
    private $output;

    /**
     * @param resource|null $output stream that receives the summary (default: STDOUT)
     */
    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
        $output = null,
    ) {
        $this->output = $output ?? STDOUT;
        $this->downloader = new Downloader(
            new FileCache($config->cacheDir),
            $log,
            $config->httpTimeout,
            $config->httpRetries,
            $config->userAgent,
        );
        $this->reader = new SourceReader();
        $this->countries = new CountrySource($config->ipdenyBaseUrl, $config->countryAggregated);
        $this->report = new BuildReport();
    }

    /**
     * @return int process exit code
     */
    public function run(): int
    {
        $started = microtime(true);
        $this->report = new BuildReport();
        try {
            $set = $this->collect();
            if (0 === \count($set)) {
                $this->log->error('No ranges collected, nothing written');

                return 1;
            }

            $this->report->merged = $this->config->mergeRanges;
            if ($this->config->mergeRanges) {
                $before = \count($set);
                $this->report->mergedAway = $set->merge();
                $this->log->info(\sprintf('Merged overlapping/adjacent ranges: %s -> %s (-%s)', Format::number($before), Format::number(\count($set)), Format::number($this->report->mergedAway)));
            } else {
                $set->sort();
            }
            $families = $set->countByFamily();
            $this->report->total = \count($set);
            $this->report->ipv4 = $families[IpMath::V4];
            $this->report->ipv6 = $families[IpMath::V6];

            $this->writeOutputs($set);
            fwrite($this->output, (new SummaryPrinter())->render($this->report, microtime(true) - $started, $this->log->warnings));

            return 0;
        } catch (\Throwable $e) {
            $this->log->error($e->getMessage());
            $this->log->debug($e->getTraceAsString());

            return 1;
        }
    }

    public function report(): BuildReport
    {
        return $this->report;
    }

    private function collect(): RangeSet
    {
        $set = new RangeSet();
        $this->log->info(\sprintf(
            'Building "%s" from %d source(s) and %d countr%s -> formats: %s',
            $this->config->outputName,
            \count($this->config->sources),
            \count($this->config->countries),
            1 === \count($this->config->countries) ? 'y' : 'ies',
            implode(', ', $this->config->outputFormats),
        ));

        foreach ($this->config->sources as $source) {
            $this->collectSource($set, $source);
        }
        foreach ($this->config->countries as $country) {
            $this->collectCountry($set, $country);
        }

        return $set;
    }

    private function collectSource(RangeSet $set, string $source): void
    {
        $this->log->info("Source: $source");
        try {
            $fetch = $this->downloader->fetch($source, $this->config->sourceCacheTtl);
            $this->log->info('  '.$fetch->describe());
            $stats = $this->ingest($set, $fetch->path, self::nameHint($source), self::defaultLabel($source));
            if (0 === $stats->ranges) {
                throw new \RuntimeException("No valid ranges found in $source (formats: ".implode(', ', $stats->uniqueFormats()).')');
            }
            $this->report->sources[] = new SourceReport($source, $fetch, $stats);
        } catch (\RuntimeException $e) {
            $this->sourceFailure('source', $source, $e);
        }
    }

    private function collectCountry(RangeSet $set, string $country): void
    {
        $urls = [];
        if ($this->config->countryIpv4) {
            $urls['IPv4'] = $this->countries->ipv4Url($country);
        }
        if ($this->config->countryIpv6) {
            $urls['IPv6'] = $this->countries->ipv6Url($country);
        }
        $missing = [];
        foreach ($urls as $family => $url) {
            $this->log->info('Country '.strtoupper($country)." $family: $url");
            try {
                $fetch = $this->downloader->fetch($url, $this->config->cacheTtl);
                $this->log->info('  '.$fetch->describe());
                $stats = $this->ingest($set, $fetch->path, self::nameHint($url), CountrySource::label($country));
                if (0 === $stats->ranges && $stats->malformed > 0) {
                    throw new \RuntimeException("No valid ranges found in $url");
                }
                $this->report->countries[] = new CountryReport($country, $family, $url, $fetch, $stats);
            } catch (DownloadException $e) {
                if (!$e->isNotFound()) {
                    $this->sourceFailure('country zone', $url, $e);
                    continue;
                }
                // ipdeny.com has no zone file when a country has no allocations in that family (e.g. no IPv6 for KP).
                $missing[] = $family;
                $this->report->countries[] = CountryReport::unavailable($country, $family, $url);
            } catch (\RuntimeException $e) {
                $this->sourceFailure('country zone', $url, $e);
            }
        }
        if ([] !== $missing && \count($missing) === \count($urls)) {
            $this->sourceFailure('country', strtoupper($country), new \RuntimeException('ipdeny.com has no zone files for this country code - is it a valid ISO 3166-1 alpha-2 code?'));
        } elseif ([] !== $missing) {
            $families = implode('/', $missing);
            $this->log->warn(\sprintf('Country %s: no %s zone on ipdeny.com (the country has no %s allocations), skipped', strtoupper($country), $families, $families));
        }
    }

    private function sourceFailure(string $kind, string $what, \RuntimeException $e): void
    {
        if ($this->config->failOnSourceError) {
            throw new \RuntimeException("Failed to load $kind $what: ".$e->getMessage().' (set FAIL_ON_SOURCE_ERROR=false to skip instead)', 0, $e);
        }
        $this->log->warn("Skipping $kind $what: ".$e->getMessage());
        $this->report->skipped[] = new SkippedSource($kind, $what, $e->getMessage());
    }

    private function ingest(RangeSet $set, string $path, string $nameHint, string $defaultLabel): ParseStats
    {
        $stats = new ParseStats();
        foreach ($this->reader->read($path, $defaultLabel, $nameHint, $stats) as $range) {
            if ($set->add($range)) {
                ++$stats->added;
            }
        }
        $this->log->info(\sprintf(
            '  %s: %s ranges (%s new, %s duplicates, %s malformed, %s not blocked)',
            implode('+', $stats->uniqueFormats()),
            Format::number($stats->ranges),
            Format::number($stats->added),
            Format::number($stats->ranges - $stats->added),
            Format::number($stats->malformed),
            Format::number($stats->ignored),
        ));
        foreach ($stats->malformedSamples as $sample) {
            $this->log->warn('  malformed line: '.mb_strimwidth($sample, 0, 120, '...'));
        }

        return $stats;
    }

    private function writeOutputs(RangeSet $set): void
    {
        $header = $this->header();
        foreach ($this->config->outputFormats as $format) {
            $writer = $this->writer($format);
            $path = $this->config->outputDir.'/'.$this->config->outputName.'.'.$writer->extension();
            $result = $writer->write($path, $set->ranges(), $header);
            $file = OutputFile::fromPath($path, $result->written, $result->omitted);
            $this->report->files[] = $file;
            $this->log->info(\sprintf(
                'Wrote %s (%s, %s ranges%s)',
                $path,
                Format::bytes($file->bytes),
                Format::number($result->written),
                $result->omitted > 0 ? ', '.Format::number($result->omitted).' IPv6 ranges omitted - format is IPv4 only' : '',
            ));
            if ($this->config->outputGzip) {
                self::gzip($path);
                $gz = OutputFile::fromPath($path.'.gz', $result->written, $result->omitted);
                $this->report->files[] = $gz;
                $this->log->info(\sprintf('Wrote %s (%s)', $gz->path, Format::bytes($gz->bytes)));
            }
        }

        if ($this->config->outputManifest) {
            $manifestPath = $this->config->outputDir.'/'.$this->config->outputName.'.json';
            (new ManifestWriter(self::PROJECT_URL))->write($manifestPath, $this->config->outputName, $this->report);
            $this->log->info("Wrote $manifestPath");
        }
    }

    private function writer(string $format): WriterInterface
    {
        return match ($format) {
            'p2p' => new P2pWriter(),
            'dat' => new DatWriter(),
            'p2b' => new P2bWriter($this->config->p2bVersion),
            default => throw new \InvalidArgumentException("Unknown output format $format"),
        };
    }

    /**
     * @return list<string>
     */
    private function header(): array
    {
        $lines = [
            $this->config->outputName.' - IP blocklist for qBittorrent, generated with '.self::PROJECT_URL,
            'Sources:',
        ];
        foreach ($this->config->sources as $source) {
            $lines[] = '  - '.$source;
        }
        if ([] !== $this->config->countries) {
            $lines[] = 'Blocked countries (zones from ipdeny.com): '.strtoupper(implode(', ', $this->config->countries));
        }
        $lines[] = \sprintf('Ranges: %s (IPv4: %s, IPv6: %s)', Format::number($this->report->total), Format::number($this->report->ipv4), Format::number($this->report->ipv6));

        return $lines;
    }

    private static function gzip(string $path): void
    {
        $in = @fopen($path, 'rb');
        $out = @fopen('compress.zlib://'.$path.'.gz.tmp', 'wb9');
        if (false === $in || false === $out) {
            throw new \RuntimeException("Cannot gzip $path");
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if (!@rename($path.'.gz.tmp', $path.'.gz')) {
            throw new \RuntimeException("Cannot move $path.gz.tmp");
        }
        @chmod($path.'.gz', 0644);
    }

    /**
     * File name of a URL/path, used to pick the parser from the extension.
     */
    public static function nameHint(string $source): string
    {
        $path = preg_match('#^https?://#i', $source) ? (string) parse_url($source, \PHP_URL_PATH) : $source;
        $name = basename(rawurldecode($path));

        return '' === $name ? 'source' : $name;
    }

    /**
     * Label for ranges of plain lists that carry no label of their own.
     */
    public static function defaultLabel(string $source): string
    {
        $host = preg_match('#^https?://#i', $source) ? (string) parse_url($source, \PHP_URL_HOST) : '';
        $hint = self::nameHint($source);
        $name = preg_replace('/\.(zip|gz)$/i', '', $hint) ?? $hint;

        return '' !== $host ? "$name ($host)" : $name;
    }
}
