<?php

declare(strict_types=1);

namespace Blocklist\Writer;

final class Label
{
    public const string FALLBACK = 'Blocked';

    /**
     * Removes control characters and surplus whitespace; never returns an empty string.
     */
    public static function clean(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s{2,}/', ' ', $name) ?? '');

        return '' === $name ? self::FALLBACK : $name;
    }
}
