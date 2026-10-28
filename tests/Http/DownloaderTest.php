<?php

declare(strict_types=1);

namespace Blocklist\Tests\Http;

use Blocklist\Http\Downloader;
use Blocklist\Http\DownloadException;
use Blocklist\Http\FetchResult;
use Blocklist\Http\FetchStatus;
use Blocklist\Http\FileCache;
use Blocklist\Http\HttpResponse;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use Blocklist\Tests\Support\TestHttpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Downloader::class)]
#[CoversClass(DownloadException::class)]
#[CoversClass(FetchResult::class)]
#[CoversClass(HttpResponse::class)]
final class DownloaderTest extends TestCase
{
    use TempDirectoryTrait;

    private static TestHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = TestHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testDownloadsCachesAndRevalidatesWithEtag(): void
    {
        self::$server->put('de.zone', "1.0.0.0/24\n");
        [$cache, $downloader] = $this->downloader();
        $url = self::$server->baseUrl.'/de.zone';

        $first = $downloader->fetch($url, 3600);
        self::assertSame(FetchStatus::Downloaded, $first->status);
        self::assertSame("1.0.0.0/24\n", Helpers::read($first->path));
        $entry = $cache->lookup($url);
        self::assertNotNull($entry);
        self::assertNotNull($entry->etag);
        self::assertNotNull($entry->lastModified);

        self::assertSame(FetchStatus::Cached, $downloader->fetch($url, 3600)->status, 'fresh entry: no request');

        sleep(1);
        self::assertSame(FetchStatus::Revalidated, $downloader->fetch($url, 0)->status, 'ttl 0: conditional request answered with 304');
        self::assertGreaterThan($entry->fetchedAt, $cache->lookup($url)?->fetchedAt, '304 refreshes the timestamp');

        self::$server->put('de.zone', "1.0.0.0/24\n2.0.0.0/24\n");
        $updated = $downloader->fetch($url, 0);
        self::assertSame(FetchStatus::Downloaded, $updated->status, 'changed ETag: new download');
        self::assertSame("1.0.0.0/24\n2.0.0.0/24\n", Helpers::read($updated->path));
    }

    public function testFollowsRedirects(): void
    {
        self::$server->put('de.zone', "3.0.0.0/8\n");
        [, $downloader] = $this->downloader();

        self::assertSame("3.0.0.0/8\n", Helpers::read($downloader->fetch(self::$server->baseUrl.'/redirect', 0)->path));
    }

    public function testReportsNotFoundWithoutRetrying(): void
    {
        [, $downloader] = $this->downloader();

        try {
            $downloader->fetch(self::$server->baseUrl.'/missing', 0);
            self::fail('404 must throw');
        } catch (DownloadException $e) {
            self::assertTrue($e->isNotFound());
            self::assertSame(404, $e->httpCode);
        }
    }

    public function testRetriesServerErrors(): void
    {
        [, $downloader] = $this->downloader();
        $url = self::$server->baseUrl.'/flaky?fail=1&case='.bin2hex(random_bytes(4));

        self::assertSame("7.0.0.0/8\n", Helpers::read($downloader->fetch($url, 0)->path));
    }

    public function testFailsAfterExhaustedRetriesWithoutCachedCopy(): void
    {
        [, $downloader] = $this->downloader();

        try {
            $downloader->fetch(self::$server->baseUrl.'/flaky?fail=99&case='.bin2hex(random_bytes(4)), 0);
            self::fail('must throw after the retries are exhausted');
        } catch (DownloadException $e) {
            self::assertSame(500, $e->httpCode);
            self::assertFalse($e->isNotFound());
        }
    }

    public function testFallsBackToStaleCacheWhenTheDownloadFails(): void
    {
        [, $downloader] = $this->downloader();
        $url = self::$server->baseUrl.'/stale?case='.bin2hex(random_bytes(4));

        self::assertSame(FetchStatus::Downloaded, $downloader->fetch($url, 0)->status);
        $stale = $downloader->fetch($url, 0);
        self::assertSame(FetchStatus::Stale, $stale->status);
        self::assertSame("8.0.0.0/8\n", Helpers::read($stale->path));
        self::assertStringContainsString('STALE', $stale->describe());
    }

    public function testFallsBackToStaleCacheOnConnectionErrors(): void
    {
        $dir = $this->createTempDirectory();
        $cache = new FileCache("$dir/cache");
        $downloader = new Downloader($cache, Helpers::silentLogger(), 2, 0, 'test');
        $url = 'http://127.0.0.1:9/unreachable.zone'; // port 9 (discard) refuses connections
        $temp = (string) tempnam("$dir/cache", 'dl');
        file_put_contents($temp, "5.0.0.0/8\n");
        $cache->store($url, $temp, []);

        self::assertSame(FetchStatus::Cached, $downloader->fetch($url, 3600)->status);
        self::assertSame(FetchStatus::Stale, $downloader->fetch($url, 0)->status);

        $this->expectException(DownloadException::class);
        $downloader->fetch('http://127.0.0.1:9/never-cached.zone', 0);
    }

    public function testPassesLocalFilesThrough(): void
    {
        $dir = $this->createTempDirectory();
        [, $downloader] = $this->downloader();
        file_put_contents("$dir/local.p2p", "A:1.2.3.4-1.2.3.4\n");

        $result = $downloader->fetch("$dir/local.p2p", 0);
        self::assertSame(FetchStatus::Local, $result->status);
        self::assertSame("$dir/local.p2p", $result->path);

        $this->expectException(\RuntimeException::class);
        $downloader->fetch("$dir/does-not-exist", 0);
    }

    /**
     * @return array{FileCache, Downloader}
     */
    private function downloader(): array
    {
        $cache = new FileCache($this->createTempDirectory().'/cache');

        return [$cache, new Downloader($cache, Helpers::silentLogger(), 5, 1, 'test')];
    }
}
