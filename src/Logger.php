<?php

declare(strict_types=1);

namespace Blocklist;

/**
 * Tiny leveled logger writing to stderr (stdout is reserved for the final summary).
 */
final class Logger
{
    public const array LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    private readonly int $threshold;

    /** @var resource */
    private $stream;

    public int $warnings = 0;
    public int $errors = 0;

    /**
     * @param resource|null $stream defaults to STDERR
     */
    public function __construct(string $level = 'info', $stream = null)
    {
        $this->threshold = self::LEVELS[strtolower($level)] ?? self::LEVELS['info'];
        $this->stream = $stream ?? STDERR;
    }

    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    public function warn(string $message): void
    {
        ++$this->warnings;
        $this->log('warn', $message);
    }

    public function error(string $message): void
    {
        ++$this->errors;
        $this->log('error', $message);
    }

    /**
     * @param key-of<self::LEVELS> $level
     */
    private function log(string $level, string $message): void
    {
        if (self::LEVELS[$level] < $this->threshold) {
            return;
        }
        fwrite($this->stream, \sprintf("%s %-5s %s\n", gmdate('H:i:s'), strtoupper($level), $message));
    }
}
