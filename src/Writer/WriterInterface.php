<?php

declare(strict_types=1);

namespace Blocklist\Writer;

use Blocklist\Ip\IpRange;

interface WriterInterface
{
    public function extension(): string;

    /**
     * @param iterable<IpRange> $ranges sorted ranges
     * @param list<string>      $header comment lines (ignored by binary formats)
     */
    public function write(string $path, iterable $ranges, array $header): WriteResult;
}
