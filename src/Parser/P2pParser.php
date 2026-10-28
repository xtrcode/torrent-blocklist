<?php

declare(strict_types=1);

namespace Blocklist\Parser;

use Blocklist\Ip\IpMath;
use Blocklist\Ip\IpRange;

/**
 * PeerGuardian text format: "Label:1.2.3.0-1.2.3.255" (IPv6 ranges allowed).
 *
 * Mirrors qBittorrent's parser: the range separator is the LAST dash in the line, and the label
 * ends at the first colon after which the remainder parses as an IP address - so labels may
 * contain colons and IPv6 addresses are unambiguous.
 */
final class P2pParser implements LineParserInterface
{
    public function parseLine(string $line, string $defaultName): IpRange|LineStatus
    {
        $dash = strrpos($line, '-');
        if (false === $dash) {
            return LineStatus::Malformed;
        }
        $end = IpMath::parse(substr($line, $dash + 1));
        if (null === $end) {
            return LineStatus::Malformed;
        }
        $left = substr($line, 0, $dash);
        $offset = 0;
        while (false !== ($colon = strpos($left, ':', $offset))) {
            $start = IpMath::parse(substr($left, $colon + 1));
            if (null !== $start) {
                if ($start[0] !== $end[0]) {
                    return LineStatus::Malformed;
                }
                $name = trim(substr($left, 0, $colon));

                return new IpRange($start[0], $start[1], $end[1], '' === $name ? $defaultName : $name);
            }
            $offset = $colon + 1;
        }

        return LineStatus::Malformed;
    }
}
