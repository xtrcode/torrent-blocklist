<?php

declare(strict_types=1);

namespace Blocklist\Writer;

/**
 * PeerGuardian binary format (http://wiki.phoenixlabs.org/wiki/P2B_Format), IPv4 only.
 *
 *   header   : FF FF FF FF 'P' '2' 'B' <version>
 *   v1 / v2  : repeated { label\0 (v1 ISO-8859-1, v2 UTF-8), start (4 bytes BE), end (4 bytes BE) }
 *   v3       : label count (4 BE), labels\0 (UTF-8), range count (4 BE),
 *              repeated { label index (4 BE), start (4 BE), end (4 BE) }
 */
final readonly class P2bWriter implements WriterInterface
{
    private const string MAGIC = "\xFF\xFF\xFF\xFFP2B";

    public function __construct(private int $version = 3)
    {
        if ($version < 1 || $version > 3) {
            throw new \InvalidArgumentException('P2B version must be 1, 2 or 3');
        }
    }

    public function extension(): string
    {
        return 'p2b';
    }

    public function write(string $path, iterable $ranges, array $header): WriteResult
    {
        return AtomicFile::write($path, function (AtomicFile $file) use ($ranges): WriteResult {
            $file->append(self::MAGIC.\chr($this->version));

            return 3 === $this->version ? $this->writeV3($file, $ranges) : $this->writeV1V2($file, $ranges);
        });
    }

    /**
     * @param iterable<\Blocklist\Ip\IpRange> $ranges
     */
    private function writeV3(AtomicFile $file, iterable $ranges): WriteResult
    {
        $labels = [];
        $body = '';
        $written = 0;
        $omitted = 0;
        foreach ($ranges as $range) {
            if (!$range->isV4()) {
                ++$omitted;
                continue;
            }
            $label = $this->label($range->name);
            $index = $labels[$label] ??= \count($labels);
            // Binary addresses are already in network byte order.
            $body .= pack('N', $index).$range->start.$range->end;
            ++$written;
        }
        $file->append(pack('N', \count($labels)));
        foreach (array_keys($labels) as $label) {
            $file->append($label."\0");
        }
        $file->append(pack('N', $written));
        $file->append($body);

        return new WriteResult($written, $omitted);
    }

    /**
     * @param iterable<\Blocklist\Ip\IpRange> $ranges
     */
    private function writeV1V2(AtomicFile $file, iterable $ranges): WriteResult
    {
        $written = 0;
        $omitted = 0;
        foreach ($ranges as $range) {
            if (!$range->isV4()) {
                ++$omitted;
                continue;
            }
            $file->append($this->label($range->name)."\0".$range->start.$range->end);
            ++$written;
        }

        return new WriteResult($written, $omitted);
    }

    private function label(string $name): string
    {
        $label = Label::clean($name);
        if (1 === $this->version) {
            $label = mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8');
        }

        return str_replace("\0", '', $label);
    }
}
