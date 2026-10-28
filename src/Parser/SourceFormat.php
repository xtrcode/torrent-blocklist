<?php

declare(strict_types=1);

namespace Blocklist\Parser;

enum SourceFormat: string
{
    case P2p = 'p2p';
    case Dat = 'dat';
    case Cidr = 'cidr';

    public function parser(): LineParserInterface
    {
        return match ($this) {
            self::P2p => new P2pParser(),
            self::Dat => new DatParser(),
            self::Cidr => new CidrParser(),
        };
    }

    /**
     * Detects the format from a file name, null when the extension says nothing.
     */
    public static function fromFileName(string $name): ?self
    {
        return match (strtolower(pathinfo($name, \PATHINFO_EXTENSION))) {
            'p2p' => self::P2p,
            'dat' => self::Dat,
            'zone', 'cidr', 'netset', 'ipset' => self::Cidr,
            default => null,
        };
    }

    /**
     * Detects the format from the first lines of a file. Falls back to CIDR.
     *
     * @param list<string> $lines
     */
    public static function sniff(array $lines): self
    {
        $scores = [self::Cidr->value => 0, self::Dat->value => 0, self::P2p->value => 0];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ('' === $line || SourceReader::isComment($line)) {
                continue;
            }
            if (preg_match('#^[0-9A-Fa-f.:]+(/\d{1,3})?$#', $line) || preg_match('/^[0-9A-Fa-f.:]+\s*-\s*[0-9A-Fa-f.:]+$/', $line)) {
                ++$scores[self::Cidr->value];
            } elseif (preg_match('/^[0-9A-Fa-f.:]+\s*-\s*[0-9A-Fa-f.:]+\s*,/', $line)) {
                ++$scores[self::Dat->value];
            } elseif (preg_match('/:\s*[0-9A-Fa-f.:]+\s*-\s*[0-9A-Fa-f.:]+$/', $line)) {
                ++$scores[self::P2p->value];
            }
        }
        arsort($scores);
        $best = (string) array_key_first($scores);

        return $scores[$best] > 0 ? self::from($best) : self::Cidr;
    }
}
