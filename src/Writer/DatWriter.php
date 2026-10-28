<?php

declare(strict_types=1);

namespace Blocklist\Writer;

use Blocklist\Ip\IpMath;

/**
 * eMule ipfilter.dat format ("001.002.003.000 - 001.002.003.255 , 000 , Label"), IPv4 and IPv6.
 * Access level 000 = blocked.
 */
final class DatWriter implements WriterInterface
{
    public function extension(): string
    {
        return 'dat';
    }

    public function write(string $path, iterable $ranges, array $header): WriteResult
    {
        return AtomicFile::write($path, static function (AtomicFile $file) use ($ranges, $header): WriteResult {
            foreach ($header as $line) {
                $file->append('# '.$line."\n");
            }
            $written = 0;
            foreach ($ranges as $range) {
                if ($range->isV4()) {
                    $start = IpMath::formatPadded($range->start);
                    $end = IpMath::formatPadded($range->end);
                } else {
                    $start = $range->startText();
                    $end = $range->endText();
                }
                $file->append($start.' - '.$end.' , 000 , '.Label::clean($range->name)."\n");
                ++$written;
            }

            return new WriteResult($written);
        });
    }
}
