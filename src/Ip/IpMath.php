<?php

declare(strict_types=1);

namespace Blocklist\Ip;

/**
 * IP addresses are handled as fixed-length binary strings (4 bytes for IPv4, 16 bytes for IPv6)
 * so that ordering is a plain strcmp() and no 128-bit integer support is needed.
 */
final class IpMath
{
    public const int V4 = 4;
    public const int V6 = 6;

    /**
     * Parses an address the same way qBittorrent does: dotted quad (leading zeros allowed,
     * as used in eMule .dat files) or anything inet_pton() accepts as IPv6.
     *
     * @return array{0: self::V4|self::V6, 1: string}|null [family, binary address]
     */
    public static function parse(string $ip): ?array
    {
        $ip = trim($ip);
        if ('' === $ip) {
            return null;
        }
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $ip, $m)) {
            $binary = '';
            for ($i = 1; $i <= 4; ++$i) {
                $octet = (int) $m[$i];
                if ($octet > 255) {
                    return null;
                }
                $binary .= \chr($octet);
            }

            return [self::V4, $binary];
        }
        if (str_contains($ip, ':')) {
            $binary = @inet_pton($ip);
            if (false !== $binary && 16 === \strlen($binary)) {
                return [self::V6, $binary];
            }
        }

        return null;
    }

    public static function format(string $binary): string
    {
        $text = 4 === \strlen($binary) || 16 === \strlen($binary) ? inet_ntop($binary) : false;
        if (false === $text) {
            throw new \InvalidArgumentException('Invalid binary IP address');
        }

        return $text;
    }

    /**
     * Zero-padded dotted quad as traditionally used in eMule ipfilter.dat (001.002.003.004).
     */
    public static function formatPadded(string $binary): string
    {
        if (4 !== \strlen($binary)) {
            throw new \InvalidArgumentException('formatPadded() expects a 4 byte IPv4 address');
        }

        return \sprintf('%03d.%03d.%03d.%03d', \ord($binary[0]), \ord($binary[1]), \ord($binary[2]), \ord($binary[3]));
    }

    /**
     * @return array{0: self::V4|self::V6, 1: string, 2: string}|null [family, first address, last address]
     */
    public static function cidrToRange(string $cidr): ?array
    {
        $parts = explode('/', trim($cidr), 2);
        $parsed = self::parse($parts[0]);
        if (null === $parsed) {
            return null;
        }
        [$family, $binary] = $parsed;
        $bits = \strlen($binary) * 8;
        if (isset($parts[1])) {
            if (!ctype_digit($parts[1])) {
                return null;
            }
            $prefix = (int) $parts[1];
        } else {
            $prefix = $bits;
        }
        if ($prefix > $bits) {
            return null;
        }
        $mask = self::mask($prefix, \strlen($binary));

        return [$family, $binary & $mask, $binary | ~$mask];
    }

    private static function mask(int $prefix, int $length): string
    {
        $mask = str_repeat("\xFF", intdiv($prefix, 8));
        $remainder = $prefix % 8;
        if ($remainder > 0) {
            $mask .= \chr((0xFF << (8 - $remainder)) & 0xFF);
        }

        return str_pad($mask, $length, "\0");
    }

    /**
     * Returns the next address, or null when $binary is the last address of its family.
     */
    public static function increment(string $binary): ?string
    {
        for ($i = \strlen($binary) - 1; $i >= 0; --$i) {
            $byte = \ord($binary[$i]);
            if ($byte < 255) {
                $binary[$i] = \chr($byte + 1);

                return $binary;
            }
            $binary[$i] = "\0";
        }

        return null;
    }
}
