<?php

declare(strict_types=1);

namespace Blocklist\Parser;

use Blocklist\Ip\IpRange;

interface LineParserInterface
{
    /**
     * Parses one trimmed, non-empty, non-comment line.
     *
     * @param string $defaultName label for ranges whose line carries none
     */
    public function parseLine(string $line, string $defaultName): IpRange|LineStatus;
}
