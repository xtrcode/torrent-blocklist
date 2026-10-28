<?php

declare(strict_types=1);

namespace Blocklist\Parser;

final class ParseStats
{
    public const int MAX_SAMPLES = 5;

    public int $lines = 0;
    public int $ranges = 0;
    public int $malformed = 0;
    /** Valid lines that block nothing. */
    public int $ignored = 0;
    /** Ranges that were new to the RangeSet (filled in by the Generator). */
    public int $added = 0;
    /** @var list<string> detected formats, one per parsed file / archive member */
    public array $formats = [];
    /** @var list<string> first few malformed lines for diagnostics */
    public array $malformedSamples = [];

    /**
     * @return list<string>
     */
    public function uniqueFormats(): array
    {
        return array_values(array_unique($this->formats));
    }
}
