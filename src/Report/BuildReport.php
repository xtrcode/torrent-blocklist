<?php

declare(strict_types=1);

namespace Blocklist\Report;

/**
 * Everything one build did: inputs, totals and generated files. Feeds the summary and the manifest.
 */
final class BuildReport
{
    /** @var list<SourceReport> */
    public array $sources = [];
    /** @var list<CountryReport> */
    public array $countries = [];
    /** @var list<SkippedSource> */
    public array $skipped = [];
    /** @var list<OutputFile> */
    public array $files = [];

    public bool $merged = false;
    public int $mergedAway = 0;
    public int $total = 0;
    public int $ipv4 = 0;
    public int $ipv6 = 0;
}
