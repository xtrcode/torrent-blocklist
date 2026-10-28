<?php

declare(strict_types=1);

namespace Blocklist\Report;

final class Format
{
    public static function number(int $number): string
    {
        return number_format($number, 0, '.', ',');
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KiB', 'MiB', 'GiB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.1f %s', $value, $units[$unit]);
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $suffix => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size).$suffix;
                $seconds %= $size;
            }
            if (2 === \count($parts)) {
                break;
            }
        }

        return implode(' ', $parts);
    }
}
