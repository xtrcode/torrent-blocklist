<?php

declare(strict_types=1);

namespace Blocklist\Ip;

/**
 * Deduplicating, sortable collection of IP ranges.
 *
 * Every range is stored under a binary key "family | start | ~end". Sorting the keys with a
 * plain byte comparison yields the ranges ordered by family, then by start address ascending,
 * then by end address descending (because the end is stored inverted) - exactly the order the
 * merge pass needs. Keeping the set as one PHP array keeps memory low for ~500k ranges.
 */
final class RangeSet implements \Countable
{
    /** @var array<string, string> key => label */
    private array $entries = [];

    private bool $sorted = true;

    /**
     * @return bool true when the range was new
     */
    public function add(IpRange $range): bool
    {
        [$start, $end] = strcmp($range->start, $range->end) > 0 ? [$range->end, $range->start] : [$range->start, $range->end];
        $key = self::encode($range->family, $start, $end);
        if (\array_key_exists($key, $this->entries)) {
            return false;
        }
        $this->entries[$key] = $range->name;
        $this->sorted = false;

        return true;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * @return array{4: int, 6: int}
     */
    public function countByFamily(): array
    {
        $v4 = 0;
        $v6 = 0;
        foreach (array_keys($this->entries) as $key) {
            if (IpMath::V4 === \ord((string) $key)) {
                ++$v4;
            } else {
                ++$v6;
            }
        }

        return [IpMath::V4 => $v4, IpMath::V6 => $v6];
    }

    public function sort(): void
    {
        if (!$this->sorted) {
            ksort($this->entries, \SORT_STRING);
            $this->sorted = true;
        }
    }

    /**
     * Merges overlapping and directly adjacent ranges of the same family.
     * The label of the first (lowest / widest) range survives.
     *
     * @return int number of ranges removed
     */
    public function merge(): int
    {
        $this->sort();
        $merged = [];
        $current = null;
        foreach ($this->entries as $key => $name) {
            $range = self::decode((string) $key, $name);
            if (null !== $current && $current->family === $range->family) {
                $limit = IpMath::increment($current->end);
                if (null === $limit || strcmp($range->start, $limit) <= 0) {
                    if (strcmp($range->end, $current->end) > 0) {
                        $current = new IpRange($current->family, $current->start, $range->end, $current->name);
                    }
                    continue;
                }
            }
            if (null !== $current) {
                $merged[self::encode($current->family, $current->start, $current->end)] = $current->name;
            }
            $current = $range;
        }
        if (null !== $current) {
            $merged[self::encode($current->family, $current->start, $current->end)] = $current->name;
        }
        $removed = \count($this->entries) - \count($merged);
        $this->entries = $merged;

        return $removed;
    }

    /**
     * @return \Generator<int, IpRange> ranges in sorted order
     */
    public function ranges(): \Generator
    {
        $this->sort();
        foreach ($this->entries as $key => $name) {
            yield self::decode((string) $key, $name);
        }
    }

    private static function encode(int $family, string $start, string $end): string
    {
        return \chr($family).$start.~$end;
    }

    private static function decode(string $key, string $name): IpRange
    {
        $family = IpMath::V4 === \ord($key) ? IpMath::V4 : IpMath::V6;
        $length = IpMath::V4 === $family ? 4 : 16;

        return new IpRange($family, substr($key, 1, $length), ~substr($key, 1 + $length, $length), $name);
    }
}
