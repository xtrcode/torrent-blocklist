<?php

declare(strict_types=1);

namespace Blocklist\Tests\Support;

use Blocklist\Ip\IpMath;
use Blocklist\Ip\IpRange;
use Blocklist\Logger;
use Blocklist\Parser\ParseStats;
use Blocklist\Parser\SourceReader;

final class Helpers
{
    /**
     * Binary form of an IPv4 or IPv6 address.
     */
    public static function ip(string $ip): string
    {
        $parsed = IpMath::parse($ip) ?? throw new \InvalidArgumentException("Invalid IP in test: $ip");

        return $parsed[1];
    }

    public static function range(string $start, string $end, string $name = 'test'): IpRange
    {
        $first = IpMath::parse($start) ?? throw new \InvalidArgumentException("Invalid IP in test: $start");

        return new IpRange($first[0], $first[1], self::ip($end), $name);
    }

    /**
     * "start-end name" lines for readable assertions.
     *
     * @param iterable<IpRange> $ranges
     *
     * @return list<string>
     */
    public static function describe(iterable $ranges): array
    {
        $out = [];
        foreach ($ranges as $range) {
            $out[] = $range->startText().'-'.$range->endText().' '.$range->name;
        }

        return $out;
    }

    /**
     * Parses a file with the SourceReader and describes the ranges.
     *
     * @return list<string>
     */
    public static function readFile(string $path, string $defaultName = 'default', ?ParseStats $stats = null): array
    {
        return self::describe((new SourceReader())->read($path, $defaultName, basename($path), $stats ?? new ParseStats()));
    }

    /**
     * @return resource
     */
    public static function memoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open php://memory');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    public static function streamContents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public static function silentLogger(): Logger
    {
        return new Logger('error', self::memoryStream());
    }

    public static function read(string $path): string
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException("Cannot read $path");
        }

        return $content;
    }
}
