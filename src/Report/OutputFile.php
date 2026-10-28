<?php

declare(strict_types=1);

namespace Blocklist\Report;

final readonly class OutputFile
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public int $ranges,
        public int $omitted = 0,
    ) {
    }

    public static function fromPath(string $path, int $ranges, int $omitted = 0): self
    {
        $hash = hash_file('sha256', $path);
        if (false === $hash) {
            throw new \RuntimeException("Cannot hash $path");
        }

        return new self($path, (int) filesize($path), $hash, $ranges, $omitted);
    }

    public function name(): string
    {
        return basename($this->path);
    }
}
