<?php

declare(strict_types=1);

namespace Blocklist\Writer;

/**
 * PeerGuardian text format ("Label:start-end"), IPv4 and IPv6.
 */
final class P2pWriter implements WriterInterface
{
    public function extension(): string
    {
        return 'p2p';
    }

    public function write(string $path, iterable $ranges, array $header): WriteResult
    {
        return AtomicFile::write($path, static function (AtomicFile $file) use ($ranges, $header): WriteResult {
            foreach ($header as $line) {
                $file->append('# '.$line."\n");
            }
            $written = 0;
            foreach ($ranges as $range) {
                // Colons are replaced for parsers that split at the first/last colon only.
                $label = Label::clean(str_replace(':', ' ', Label::clean($range->name)));
                $file->append($label.':'.$range->startText().'-'.$range->endText()."\n");
                ++$written;
            }

            return new WriteResult($written);
        });
    }
}
