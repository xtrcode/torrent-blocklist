<?php

declare(strict_types=1);

namespace Blocklist\Parser;

use Blocklist\Ip\IpRange;

/**
 * Turns a source file into ranges. Handles .zip (via PharData, so no ext-zip is needed) and
 * .gz containers, picks the line parser from the file name or by sniffing the content.
 */
final class SourceReader
{
    private const int SNIFF_LINES = 200;

    /**
     * @param string $path        file on disk
     * @param string $defaultName label for ranges that carry none
     * @param string $nameHint    original file name (the cached file may be named differently)
     *
     * @return \Generator<int, IpRange>
     */
    public function read(string $path, string $defaultName, string $nameHint, ParseStats $stats): \Generator
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read source file: $path");
        }
        switch (self::containerKind($path)) {
            case 'zip':
                yield from $this->readZip($path, $defaultName, $stats);

                return;
            case 'gz':
                $handle = self::open('compress.zlib://'.$path);
                try {
                    yield from $this->parseStream($handle, preg_replace('/\.gz$/i', '', $nameHint) ?? $nameHint, $defaultName, $stats);
                } finally {
                    fclose($handle);
                }

                return;
            default:
                $handle = self::open($path);
                try {
                    yield from $this->parseStream($handle, $nameHint, $defaultName, $stats);
                } finally {
                    fclose($handle);
                }
        }
    }

    public static function isComment(string $line): bool
    {
        return '' !== $line && ('#' === $line[0] || ';' === $line[0] || str_starts_with($line, '//'));
    }

    /**
     * @return \Generator<int, IpRange>
     */
    private function readZip(string $path, string $defaultName, ParseStats $stats): \Generator
    {
        // The phar:// stream wrapper locates the archive by its extension.
        $copy = null;
        $archive = $path;
        if (!preg_match('/\.zip$/i', $path)) {
            $base = tempnam(sys_get_temp_dir(), 'blsrc');
            if (false === $base) {
                throw new \RuntimeException("Cannot create temporary copy of zip archive $path");
            }
            $copy = $base.'.zip';
            @unlink($base);
            if (!copy($path, $copy)) {
                throw new \RuntimeException("Cannot create temporary copy of zip archive $path");
            }
            $archive = $copy;
        }
        try {
            $members = 0;
            foreach (new \RecursiveIteratorIterator(new \PharData($archive)) as $member) {
                if (!$member instanceof \PharFileInfo || !$member->isFile()) {
                    continue;
                }
                ++$members;
                $handle = self::open($member->getPathname());
                try {
                    yield from $this->parseStream($handle, $member->getFilename(), $defaultName, $stats);
                } finally {
                    fclose($handle);
                }
            }
            if (0 === $members) {
                throw new \RuntimeException("Zip archive contains no files: $path");
            }
        } finally {
            if (null !== $copy) {
                @unlink($copy);
            }
        }
    }

    /**
     * @param resource $handle
     *
     * @return \Generator<int, IpRange>
     */
    private function parseStream($handle, string $name, string $defaultName, ParseStats $stats): \Generator
    {
        $head = [];
        $format = SourceFormat::fromFileName($name);
        if (null === $format) {
            while (\count($head) < self::SNIFF_LINES && false !== ($line = fgets($handle))) {
                $head[] = $line;
            }
            $format = SourceFormat::sniff($head);
        }
        $stats->formats[] = $format->value;
        $parser = $format->parser();

        $first = true;
        foreach (self::lines($head, $handle) as $raw) {
            ++$stats->lines;
            if ($first) {
                $first = false;
                if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                    $raw = substr($raw, 3);
                }
            }
            $line = trim($raw);
            if ('' === $line || self::isComment($line)) {
                continue;
            }
            $result = $parser->parseLine($line, $defaultName);
            if (LineStatus::Malformed === $result) {
                ++$stats->malformed;
                if (\count($stats->malformedSamples) < ParseStats::MAX_SAMPLES) {
                    $stats->malformedSamples[] = $line;
                }
                continue;
            }
            if (LineStatus::Ignored === $result) {
                ++$stats->ignored;
                continue;
            }
            ++$stats->ranges;
            yield $result;
        }
    }

    /**
     * @param list<string> $head   lines already consumed for format sniffing
     * @param resource     $handle
     *
     * @return \Generator<int, string>
     */
    private static function lines(array $head, $handle): \Generator
    {
        yield from $head;
        while (false !== ($line = fgets($handle))) {
            yield $line;
        }
    }

    private static function containerKind(string $path): string
    {
        $handle = self::open($path);
        $magic = (string) fread($handle, 4);
        fclose($handle);
        if (str_starts_with($magic, "PK\x03\x04")) {
            return 'zip';
        }
        if (str_starts_with($magic, "\x1F\x8B")) {
            return 'gz';
        }

        return 'plain';
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException("Cannot open file: $path");
        }

        return $handle;
    }
}
