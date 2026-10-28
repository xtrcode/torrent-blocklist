<?php

declare(strict_types=1);

namespace Blocklist\Http;

/**
 * Download cache on disk. Files are stored under <dir>/<host>/<path> (mirroring the URL so the
 * cache stays readable), each with a "<file>.meta.json" sidecar holding the fetch time and the
 * HTTP validators (ETag / Last-Modified) used for conditional requests.
 */
final class FileCache
{
    private const string META_SUFFIX = '.meta.json';

    public function __construct(private readonly string $dir)
    {
        self::ensureDirectory($this->dir);
        if (!is_writable($this->dir)) {
            throw new \RuntimeException("Cache directory is not writable: {$this->dir}");
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function pathFor(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts) {
            throw new \InvalidArgumentException("Invalid URL: $url");
        }
        $host = strtolower($parts['host'] ?? 'unknown');
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $s): bool => '' !== $s));
        $segments = array_map(static fn (string $s): string => self::sanitize(rawurldecode($s)), $segments);
        if ([] === $segments) {
            $segments = ['index'];
        }
        if (isset($parts['query']) && '' !== $parts['query']) {
            $segments[\count($segments) - 1] .= '_'.substr(sha1($parts['query']), 0, 8);
        }

        return $this->dir.'/'.self::sanitize($host).'/'.implode('/', $segments);
    }

    /**
     * @phpstan-impure reads the file system, the result changes after store()/touch()
     */
    public function lookup(string $url): ?CacheEntry
    {
        $path = $this->pathFor($url);
        $metaPath = $path.self::META_SUFFIX;
        if (!is_file($path) || !is_file($metaPath)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!\is_array($meta) || !isset($meta['fetched_at']) || !\is_int($meta['fetched_at'])) {
            return null;
        }

        return new CacheEntry(
            $url,
            $path,
            $meta['fetched_at'],
            isset($meta['etag']) && \is_string($meta['etag']) ? $meta['etag'] : null,
            isset($meta['last_modified']) && \is_string($meta['last_modified']) ? $meta['last_modified'] : null,
        );
    }

    /**
     * Moves a freshly downloaded temporary file into the cache (atomic rename).
     *
     * @param array<string, string> $headers lower-cased response headers
     */
    public function store(string $url, string $tempFile, array $headers): CacheEntry
    {
        $path = $this->pathFor($url);
        self::ensureDirectory(\dirname($path));
        if (!@rename($tempFile, $path)) {
            throw new \RuntimeException("Cannot move download into cache: $path");
        }
        @chmod($path, 0644);
        $entry = new CacheEntry($url, $path, time(), $headers['etag'] ?? null, $headers['last-modified'] ?? null);
        $this->writeMeta($entry);

        return $entry;
    }

    /**
     * Marks a cached file as fresh again (after a "304 Not Modified").
     */
    public function touch(CacheEntry $entry): CacheEntry
    {
        $fresh = new CacheEntry($entry->url, $entry->path, time(), $entry->etag, $entry->lastModified);
        $this->writeMeta($fresh);

        return $fresh;
    }

    private function writeMeta(CacheEntry $entry): void
    {
        $meta = [
            'url' => $entry->url,
            'fetched_at' => $entry->fetchedAt,
            'fetched_at_iso' => gmdate('c', $entry->fetchedAt),
            'etag' => $entry->etag,
            'last_modified' => $entry->lastModified,
            'size' => (int) filesize($entry->path),
        ];
        $json = json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $metaPath = $entry->path.self::META_SUFFIX;
        if (false === file_put_contents($metaPath.'.tmp', $json."\n") || !@rename($metaPath.'.tmp', $metaPath)) {
            throw new \RuntimeException("Cannot write cache metadata: $metaPath");
        }
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache directory: $dir");
        }
    }

    private static function sanitize(string $segment): string
    {
        $clean = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $segment) ?? '_', '.');

        return '' === $clean ? '_' : $clean;
    }
}
