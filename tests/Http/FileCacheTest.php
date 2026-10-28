<?php

declare(strict_types=1);

namespace Blocklist\Tests\Http;

use Blocklist\Http\CacheEntry;
use Blocklist\Http\FileCache;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileCache::class)]
#[CoversClass(CacheEntry::class)]
final class FileCacheTest extends TestCase
{
    use TempDirectoryTrait;

    public function testMapsUrlsToReadablePaths(): void
    {
        $dir = $this->createTempDirectory();
        $cache = new FileCache("$dir/cache");

        self::assertSame(
            "$dir/cache/www.ipdeny.com/ipblocks/data/aggregated/de-aggregated.zone",
            $cache->pathFor('https://www.ipdeny.com/ipblocks/data/aggregated/de-aggregated.zone'),
        );
        self::assertSame("$dir/cache/example.org/index", $cache->pathFor('https://example.org/'));
        self::assertNotSame($cache->pathFor('https://example.org/a?x=1'), $cache->pathFor('https://example.org/a?x=2'), 'query string distinguishes entries');
        self::assertStringNotContainsString('..', $cache->pathFor('https://example.org/../etc/passwd'), 'no path traversal');
    }

    public function testStoresFilesWithValidatorsAndRefreshesThem(): void
    {
        $dir = $this->createTempDirectory();
        $cache = new FileCache("$dir/cache");
        $url = 'https://www.ipdeny.com/ipblocks/data/aggregated/de-aggregated.zone';
        self::assertNull($cache->lookup($url));

        $temp = (string) tempnam("$dir/cache", 'dl');
        file_put_contents($temp, "1.2.3.0/24\n");
        $entry = $cache->store($url, $temp, ['etag' => '"abc"', 'last-modified' => 'Tue, 27 Oct 2026 11:23:15 GMT', 'content-type' => 'text/plain']);

        self::assertFileDoesNotExist($temp);
        self::assertSame("1.2.3.0/24\n", Helpers::read($entry->path));
        $found = $cache->lookup($url);
        self::assertNotNull($found);
        self::assertSame('"abc"', $found->etag);
        self::assertSame('Tue, 27 Oct 2026 11:23:15 GMT', $found->lastModified);
        self::assertLessThan(5, $found->age());

        $meta = json_decode(Helpers::read($entry->path.'.meta.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($meta);
        self::assertSame($url, $meta['url']);
        self::assertSame(11, $meta['size']);

        $touched = $cache->touch(new CacheEntry($url, $entry->path, 1000, '"abc"', null));
        self::assertGreaterThan(1000, $touched->fetchedAt);
        self::assertGreaterThan(1000, $cache->lookup($url)?->fetchedAt);
    }

    public function testIgnoresEntriesWithBrokenMetadata(): void
    {
        $dir = $this->createTempDirectory();
        $cache = new FileCache("$dir/cache");
        $url = 'https://example.org/list.p2p';
        $path = $cache->pathFor($url);
        mkdir(\dirname($path), 0777, true);
        file_put_contents($path, 'data');
        file_put_contents($path.'.meta.json', '{"fetched_at": "yesterday"}');

        self::assertNull($cache->lookup($url));
    }
}
