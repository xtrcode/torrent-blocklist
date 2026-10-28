<?php

declare(strict_types=1);

namespace Blocklist\Parser;

use Blocklist\Ip\IpMath;
use Blocklist\Ip\IpRange;

/**
 * Plain address lists as published by ipdeny.com: one CIDR block, single address or
 * "start-end" range per line. Trailing "# comment" / "; comment" parts are ignored.
 */
final class CidrParser implements LineParserInterface
{
    public function parseLine(string $line, string $defaultName): IpRange|LineStatus
    {
        $line = trim(preg_replace('/\s+[#;].*$/', '', $line) ?? $line);
        if ('' === $line) {
            return LineStatus::Ignored;
        }
        if (str_contains($line, '/')) {
            $range = IpMath::cidrToRange($line);

            return null === $range ? LineStatus::Malformed : new IpRange($range[0], $range[1], $range[2], $defaultName);
        }
        $dash = strpos($line, '-');
        if (false !== $dash) {
            $start = IpMath::parse(substr($line, 0, $dash));
            $end = IpMath::parse(substr($line, $dash + 1));
            if (null === $start || null === $end || $start[0] !== $end[0]) {
                return LineStatus::Malformed;
            }

            return new IpRange($start[0], $start[1], $end[1], $defaultName);
        }
        $ip = IpMath::parse($line);

        return null === $ip ? LineStatus::Malformed : new IpRange($ip[0], $ip[1], $ip[1], $defaultName);
    }
}
