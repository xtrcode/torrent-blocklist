<?php

declare(strict_types=1);

namespace Blocklist\Parser;

use Blocklist\Ip\IpMath;
use Blocklist\Ip\IpRange;

/**
 * eMule ipfilter.dat format: "001.002.003.000 - 001.002.003.255 , 000 , Label".
 * Ranges with an access level above 127 are not blocked and therefore ignored.
 */
final class DatParser implements LineParserInterface
{
    public function parseLine(string $line, string $defaultName): IpRange|LineStatus
    {
        $parts = explode(',', $line, 3);
        if (isset($parts[1])) {
            $access = trim($parts[1]);
            if ('' !== $access && !ctype_digit($access)) {
                return LineStatus::Malformed;
            }
            if ('' !== $access && (int) $access > 127) {
                return LineStatus::Ignored;
            }
        }
        $range = trim($parts[0]);
        $dash = strpos($range, '-');
        if (false === $dash) {
            return LineStatus::Malformed;
        }
        $start = IpMath::parse(substr($range, 0, $dash));
        $end = IpMath::parse(substr($range, $dash + 1));
        if (null === $start || null === $end || $start[0] !== $end[0]) {
            return LineStatus::Malformed;
        }
        $name = isset($parts[2]) ? trim($parts[2]) : '';

        return new IpRange($start[0], $start[1], $end[1], '' === $name ? $defaultName : $name);
    }
}
