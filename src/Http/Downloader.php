<?php

declare(strict_types=1);

namespace Blocklist\Http;

use Blocklist\Logger;
use Blocklist\Report\Format;

/**
 * Fetches sources through the FileCache:
 *   - fresh cache entry (younger than the TTL)  -> used without any network access
 *   - stale / missing entry                     -> conditional GET (ETag / Last-Modified);
 *                                                  "304 Not Modified" just refreshes the entry
 *   - download failure with a cached copy       -> the stale copy is used with a warning
 *                                                  (except for 404: the resource is gone)
 *   - local paths                               -> returned as they are.
 */
final readonly class Downloader
{
    public function __construct(
        private FileCache $cache,
        private Logger $log,
        private int $timeout = 120,
        private int $retries = 3,
        private string $userAgent = 'torrent-blocklist',
    ) {
    }

    public function fetch(string $source, int $ttl): FetchResult
    {
        if (!preg_match('#^https?://#i', $source)) {
            if (!is_file($source)) {
                throw new \RuntimeException("Local source not found: $source");
            }

            return new FetchResult($source, FetchStatus::Local);
        }

        $entry = $this->cache->lookup($source);
        if (null !== $entry && $ttl > 0 && $entry->age() < $ttl) {
            $this->log->debug("Cache hit for $source (age ".Format::duration($entry->age()).', ttl '.Format::duration($ttl).')');

            return new FetchResult($entry->path, FetchStatus::Cached, $entry->age());
        }

        try {
            return $this->download($source, $entry);
        } catch (\RuntimeException $e) {
            if (null !== $entry && !($e instanceof DownloadException && $e->isNotFound())) {
                $this->log->warn($e->getMessage().' - using stale cached copy from '.gmdate('Y-m-d H:i', $entry->fetchedAt).' UTC');

                return new FetchResult($entry->path, FetchStatus::Stale, $entry->age());
            }
            throw $e;
        }
    }

    private function download(string $url, ?CacheEntry $entry): FetchResult
    {
        $temp = tempnam($this->cache->dir(), '.download-');
        if (false === $temp) {
            throw new \RuntimeException('Cannot create temporary file in '.$this->cache->dir());
        }

        $attempt = 0;
        $lastError = '';
        $lastCode = 0;
        try {
            while (true) {
                ++$attempt;
                $this->log->debug("GET $url (attempt $attempt)");
                $response = $this->request($url, $temp, $entry);

                if ($response->ok && 304 === $response->code && null !== $entry) {
                    $fresh = $this->cache->touch($entry);

                    return new FetchResult($fresh->path, FetchStatus::Revalidated);
                }
                if ($response->ok && $response->code >= 200 && $response->code < 300) {
                    $stored = $this->cache->store($url, $temp, $response->headers);
                    $temp = null;

                    return new FetchResult($stored->path, FetchStatus::Downloaded);
                }

                $lastError = $response->ok ? "HTTP {$response->code}" : "curl error: {$response->error}";
                $lastCode = $response->ok ? $response->code : 0;
                if (!$response->isRetryable() || $attempt > $this->retries) {
                    break;
                }
                $delay = min(2 ** ($attempt - 1), 15);
                $this->log->warn("$lastError for $url - retrying in {$delay}s");
                sleep($delay);
            }
        } finally {
            if (null !== $temp) {
                @unlink($temp);
            }
        }
        throw new DownloadException("Download failed: $url ($lastError)", $lastCode);
    }

    private function request(string $url, string $temp, ?CacheEntry $entry): HttpResponse
    {
        $file = fopen($temp, 'wb');
        if (false === $file) {
            throw new \RuntimeException("Cannot write temporary file $temp");
        }
        $requestHeaders = ['Accept: */*'];
        if (null !== $entry?->etag) {
            $requestHeaders[] = 'If-None-Match: '.$entry->etag;
        } elseif (null !== $entry?->lastModified) {
            $requestHeaders[] = 'If-Modified-Since: '.$entry->lastModified;
        }
        $responseHeaders = [];

        $ch = curl_init($url);
        if (false === $ch) {
            fclose($file);
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            \CURLOPT_FILE => $file,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 10,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_TIMEOUT => $this->timeout,
            \CURLOPT_USERAGENT => '' !== $this->userAgent ? $this->userAgent : 'torrent-blocklist',
            \CURLOPT_HTTPHEADER => $requestHeaders,
            \CURLOPT_ENCODING => '',
            \CURLOPT_FAILONERROR => false,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $line) use (&$responseHeaders): int {
                if (preg_match('#^HTTP/#i', $line)) {
                    $responseHeaders = []; // new response after a redirect
                } elseif (false !== ($pos = strpos($line, ':'))) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return \strlen($line);
            },
        ]);
        $ok = false !== curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        fclose($file);

        return new HttpResponse($ok, \is_int($code) ? $code : 0, $responseHeaders, $error);
    }
}
