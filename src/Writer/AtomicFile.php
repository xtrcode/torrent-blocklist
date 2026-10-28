<?php

declare(strict_types=1);

namespace Blocklist\Writer;

/**
 * Writes to "<path>.tmp" and renames on close, so readers never see a half-written file.
 */
final class AtomicFile
{
    private const int BUFFER_SIZE = 1 << 20;

    /** @var resource|null */
    private $handle;
    private readonly string $temp;
    private string $buffer = '';

    public function __construct(private readonly string $path)
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create output directory: $dir");
        }
        $this->temp = $path.'.tmp';
        $handle = @fopen($this->temp, 'wb');
        if (false === $handle) {
            throw new \RuntimeException("Cannot write output file: {$this->temp}");
        }
        $this->handle = $handle;
    }

    /**
     * Runs $writer against a new AtomicFile, commits on success and removes the temp file on failure.
     *
     * @template T
     *
     * @param callable(self): T $writer
     *
     * @return T
     */
    public static function write(string $path, callable $writer): mixed
    {
        $file = new self($path);
        try {
            $result = $writer($file);
            $file->commit();

            return $result;
        } catch (\Throwable $e) {
            $file->abort();
            throw $e;
        }
    }

    public function append(string $data): void
    {
        $this->buffer .= $data;
        if (\strlen($this->buffer) >= self::BUFFER_SIZE) {
            $this->flush();
        }
    }

    public function commit(): void
    {
        $handle = $this->handle;
        if (null === $handle) {
            return;
        }
        $this->flush();
        fclose($handle);
        $this->handle = null;
        if (!@rename($this->temp, $this->path)) {
            @unlink($this->temp);
            throw new \RuntimeException("Cannot move {$this->temp} to {$this->path}");
        }
        @chmod($this->path, 0644);
    }

    public function abort(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        @unlink($this->temp);
    }

    private function flush(): void
    {
        if ('' === $this->buffer || null === $this->handle) {
            return;
        }
        if (false === fwrite($this->handle, $this->buffer)) {
            throw new \RuntimeException("Write error on {$this->temp}");
        }
        $this->buffer = '';
    }
}
