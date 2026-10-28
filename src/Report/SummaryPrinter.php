<?php

declare(strict_types=1);

namespace Blocklist\Report;

final class SummaryPrinter
{
    public function render(BuildReport $report, float $seconds, int $warnings): string
    {
        $out = "\nSummary\n=======\nSources:\n";
        foreach ($report->sources as $source) {
            $out .= \sprintf("  %-60s %10s ranges  [%s]\n", $source->source, Format::number($source->stats->ranges), $source->fetch->describe());
        }
        if ([] !== $report->countries) {
            $out .= "Countries (ipdeny.com):\n";
            foreach ($report->countries as $country) {
                $out .= \sprintf(
                    "  %-4s %-4s %-50s %10s ranges  [%s]\n",
                    strtoupper($country->country),
                    $country->family,
                    $country->url,
                    null === $country->stats ? 'n/a' : Format::number($country->stats->ranges),
                    null === $country->fetch ? 'no zone file on ipdeny.com' : $country->fetch->describe(),
                );
            }
        }
        foreach ($report->skipped as $skipped) {
            $out .= \sprintf("  SKIPPED %s %s: %s\n", $skipped->kind, $skipped->source, $skipped->error);
        }
        if ($report->merged) {
            $out .= \sprintf("Merge: removed %s overlapping/adjacent ranges\n", Format::number($report->mergedAway));
        }
        $out .= \sprintf("Total: %s ranges (IPv4: %s, IPv6: %s)\n", Format::number($report->total), Format::number($report->ipv4), Format::number($report->ipv6));
        $out .= "Output:\n";
        foreach ($report->files as $file) {
            $out .= \sprintf("  %-50s %10s  sha256 %s\n", $file->path, Format::bytes($file->bytes), substr($file->sha256, 0, 16).'...');
        }

        return $out.\sprintf("Done in %.1fs, %d warning(s)\n", $seconds, $warnings);
    }
}
