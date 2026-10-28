<?php

declare(strict_types=1);

namespace Blocklist\Writer;

use Blocklist\Report\BuildReport;

/**
 * JSON manifest with the build inputs, range counts and checksums of the generated files.
 * Deliberately contains no timestamp, so unchanged lists produce byte-identical output.
 */
final class ManifestWriter
{
    public function __construct(private readonly string $generator)
    {
    }

    public function write(string $path, string $name, BuildReport $report): void
    {
        $json = json_encode($this->build($name, $report), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        AtomicFile::write($path, static fn (AtomicFile $file) => $file->append($json."\n"));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $name, BuildReport $report): array
    {
        $sources = [];
        foreach ($report->sources as $source) {
            $sources[] = [
                'source' => $source->source,
                'formats' => $source->stats->uniqueFormats(),
                'ranges' => $source->stats->ranges,
                'malformed' => $source->stats->malformed,
            ];
        }
        $countries = [];
        foreach ($report->countries as $country) {
            $countries[strtoupper($country->country)][$country->family] = null === $country->stats
                ? ['url' => $country->url, 'ranges' => 0, 'available' => false]
                : ['url' => $country->url, 'ranges' => $country->stats->ranges];
        }
        $skipped = [];
        foreach ($report->skipped as $entry) {
            $skipped[] = ['kind' => $entry->kind, 'source' => $entry->source, 'error' => $entry->error];
        }
        $files = [];
        foreach ($report->files as $file) {
            $files[$file->name()] = ['bytes' => $file->bytes, 'sha256' => $file->sha256, 'ranges' => $file->ranges];
        }

        $notices = [];
        if ([] !== $countries) {
            $notices[] = 'Country ranges: Powered by IPDENY.COM IP database (https://www.ipdeny.com). Copyright IPDeny.com project, all rights reserved; '
                .'see https://www.ipdeny.com/copyright.php. Derived from APNIC/ARIN/RIPE NCC/LACNIC/AFRINIC registry data: '
                .'not for use in advertising, marketing or spam. Provided as is, no accuracy guarantee. '
                .'This project is not affiliated with ipdeny.com.';
        }

        return [
            'generator' => $this->generator,
            'name' => $name,
            'notices' => $notices,
            'merged' => $report->merged,
            'ranges' => ['total' => $report->total, 'ipv4' => $report->ipv4, 'ipv6' => $report->ipv6],
            'sources' => $sources,
            'countries' => $countries,
            'skipped' => $skipped,
            'files' => $files,
        ];
    }
}
