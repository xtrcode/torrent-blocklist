<?php

declare(strict_types=1);

namespace Blocklist\Tests;

use Blocklist\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromEnv([]);

        self::assertSame([Config::SPEC['BLOCKLIST_SOURCES'][0]], $config->sources);
        self::assertSame([], $config->countries);
        self::assertSame(7 * 86400, $config->cacheTtl);
        self::assertSame(0, $config->sourceCacheTtl);
        self::assertSame(['p2p', 'dat', 'p2b'], $config->outputFormats);
        self::assertTrue($config->mergeRanges);
        self::assertTrue($config->failOnSourceError);
        self::assertSame(3, $config->p2bVersion);
        self::assertSame('/cache', $config->cacheDir);
        self::assertSame('/output', $config->outputDir);
        self::assertSame('blocklist', $config->outputName);
    }

    public function testParsesListsBooleansAndDurations(): void
    {
        $config = Config::fromEnv([
            'BLOCKLIST_SOURCES' => "https://a/x.p2p, /data/y.dat\nhttps://b/z.zip",
            'BLOCK_COUNTRIES' => 'CN, ru;kp cn',
            'CACHE_TTL' => '12h',
            'SOURCE_CACHE_TTL' => '90m',
            'OUTPUT_FORMATS' => 'P2P,p2b',
            'OUTPUT_GZIP' => 'yes',
            'MERGE_RANGES' => 'off',
            'P2B_VERSION' => '2',
            'CACHE_DIR' => '/tmp/c/',
            'LOG_LEVEL' => 'DEBUG',
        ]);

        self::assertSame(['https://a/x.p2p', '/data/y.dat', 'https://b/z.zip'], $config->sources);
        self::assertSame(['cn', 'ru', 'kp'], $config->countries);
        self::assertSame(43200, $config->cacheTtl);
        self::assertSame(5400, $config->sourceCacheTtl);
        self::assertSame(['p2p', 'p2b'], $config->outputFormats);
        self::assertTrue($config->outputGzip);
        self::assertFalse($config->mergeRanges);
        self::assertSame(2, $config->p2bVersion);
        self::assertSame('/tmp/c', $config->cacheDir);
        self::assertSame('debug', $config->logLevel);
    }

    public function testEmptyValues(): void
    {
        self::assertSame([], Config::fromEnv(['BLOCKLIST_SOURCES' => '', 'BLOCK_COUNTRIES' => 'de'])->sources, 'explicitly empty sources = country-only list');
        self::assertSame(['p2p', 'dat', 'p2b'], Config::fromEnv(['OUTPUT_FORMATS' => ''])->outputFormats, 'empty value falls back to the default');
    }

    #[DataProvider('durations')]
    public function testParsesDurations(string $value, int $seconds): void
    {
        self::assertSame($seconds, Config::ttl($value, 'X'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function durations(): iterable
    {
        yield 'plain' => ['3600', 3600];
        yield 'seconds' => ['45s', 45];
        yield 'minutes' => ['90m', 5400];
        yield 'hours' => ['12h', 43200];
        yield 'days' => ['7d', 604800];
        yield 'weeks' => ['2W', 1209600];
        yield 'zero' => ['0', 0];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('invalidEnvironments')]
    public function testRejectsInvalidConfiguration(array $env): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromEnv($env);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidEnvironments(): iterable
    {
        yield 'nothing to do' => [['BLOCKLIST_SOURCES' => '', 'BLOCK_COUNTRIES' => '']];
        yield 'country name' => [['BLOCK_COUNTRIES' => 'germany']];
        yield 'unknown format' => [['OUTPUT_FORMATS' => 'p2p,txt']];
        yield 'bad duration' => [['CACHE_TTL' => 'soon']];
        yield 'bad boolean' => [['MERGE_RANGES' => 'maybe']];
        yield 'bad p2b version' => [['P2B_VERSION' => '4']];
        yield 'bad integer' => [['HTTP_TIMEOUT' => 'ten']];
        yield 'path in output name' => [['OUTPUT_NAME' => '../x']];
        yield 'no address family' => [['BLOCK_COUNTRIES' => 'de', 'COUNTRY_IPV4' => 'false', 'COUNTRY_IPV6' => 'false']];
        yield 'ipdeny not http' => [['IPDENY_BASE_URL' => 'ftp://x']];
        yield 'log level' => [['LOG_LEVEL' => 'verbose']];
    }

    public function testHelpListsEveryVariable(): void
    {
        $help = Config::help();
        foreach (array_keys(Config::SPEC) as $name) {
            self::assertStringContainsString($name, $help);
        }
    }
}
