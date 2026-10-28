<?php

declare(strict_types=1);

namespace Blocklist;

/**
 * All configuration comes from environment variables. See self::SPEC for the list.
 */
final class Config
{
    public const array FORMATS = ['p2p', 'dat', 'p2b'];

    /** Variable name => [default value, description]. */
    public const array SPEC = [
        'BLOCKLIST_SOURCES' => [
            'https://raw.githubusercontent.com/waelisa/Best-blocklist/main/wael.list.p2p.zip',
            'Blocklist sources (URLs or paths inside the container), separated by commas, whitespace or newlines. '
            .'Supported: PeerGuardian .p2p, eMule .dat and plain CIDR/IP lists, optionally packed as .zip or .gz. '
            .'Set to an empty value to build a country-only list.',
        ],
        'BLOCK_COUNTRIES' => [
            '',
            'Countries whose complete address space is blocked, as ISO 3166-1 alpha-2 codes from ipdeny.com, '
            .'e.g. "cn,ru". Empty = no country blocking.',
        ],
        'COUNTRY_IPV4' => ['true', 'Include the IPv4 zones of the blocked countries.'],
        'COUNTRY_IPV6' => ['true', 'Include the IPv6 zones of the blocked countries (only written to .p2p and .dat).'],
        'COUNTRY_AGGREGATED' => ['true', 'Download the aggregated (pre-merged) zone files from ipdeny.com.'],
        'IPDENY_BASE_URL' => ['https://www.ipdeny.com', 'Base URL of the ipdeny.com zone files.'],
        'CACHE_DIR' => ['/cache', 'Directory for cached downloads. Mount it as a volume to keep the cache between runs.'],
        'CACHE_TTL' => [
            '7d',
            'How long a downloaded country zone is reused before ipdeny.com is contacted again '
            .'(number with optional unit s/m/h/d/w, e.g. "12h" or "7d"; 0 = revalidate on every run).',
        ],
        'SOURCE_CACHE_TTL' => [
            '0',
            'Same for BLOCKLIST_SOURCES. 0 = ask the server for a new version on every run (conditional request); '
            .'a cached copy is always kept as fallback in case the download fails.',
        ],
        'OUTPUT_DIR' => ['/output', 'Directory the generated files are written to.'],
        'OUTPUT_NAME' => ['blocklist', 'Base name of the generated files (<name>.p2p, <name>.dat, <name>.p2b, <name>.json).'],
        'OUTPUT_FORMATS' => ['p2p,dat,p2b', 'Which qBittorrent formats to write: p2p (PeerGuardian text), dat (eMule), p2b (PeerGuardian binary).'],
        'OUTPUT_GZIP' => ['false', 'Additionally write gzip-compressed copies (<name>.<format>.gz).'],
        'OUTPUT_MANIFEST' => ['true', 'Write <name>.json with sources, range counts and SHA-256 checksums of the generated files.'],
        'MERGE_RANGES' => ['true', 'Merge overlapping and adjacent ranges (smaller files, faster loading). The first range\'s label is kept.'],
        'P2B_VERSION' => ['3', 'PeerGuardian binary format version to write: 1, 2 or 3 (qBittorrent reads all three).'],
        'HTTP_TIMEOUT' => ['120', 'Download timeout in seconds.'],
        'HTTP_RETRIES' => ['3', 'Number of retries for failed downloads.'],
        'HTTP_USER_AGENT' => ['torrent-blocklist/1.0 (+https://github.com/xtrcode/torrent-blocklist)', 'User-Agent header sent with downloads.'],
        'FAIL_ON_SOURCE_ERROR' => [
            'true',
            'Abort with exit code 1 when a source or country zone cannot be loaded and no cached copy exists. '
            .'If false, the source is skipped with a warning.',
        ],
        'LOG_LEVEL' => ['info', 'Log verbosity on stderr: debug, info, warn or error.'],
    ];

    /** @var list<string> */
    public array $sources = [];
    /** @var list<string> */
    public array $countries = [];
    public bool $countryIpv4 = true;
    public bool $countryIpv6 = true;
    public bool $countryAggregated = true;
    public string $ipdenyBaseUrl = 'https://www.ipdeny.com';
    public string $cacheDir = '/cache';
    public int $cacheTtl = 604800;
    public int $sourceCacheTtl = 0;
    public string $outputDir = '/output';
    public string $outputName = 'blocklist';
    /** @var list<string> */
    public array $outputFormats = self::FORMATS;
    public bool $outputGzip = false;
    public bool $outputManifest = true;
    public bool $mergeRanges = true;
    public int $p2bVersion = 3;
    public int $httpTimeout = 120;
    public int $httpRetries = 3;
    public string $userAgent = '';
    public bool $failOnSourceError = true;
    public string $logLevel = 'info';

    /**
     * @param array<string, string> $env usually getenv()
     *
     * @throws \InvalidArgumentException on invalid values
     */
    public static function fromEnv(array $env): self
    {
        // Empty values fall back to the default, except for the two list variables where
        // "explicitly empty" is a meaningful choice (no sources / no countries).
        $raw = static fn (string $key): string => trim((string) ($env[$key] ?? self::SPEC[$key][0]));
        $get = static function (string $key) use ($raw): string {
            $value = $raw($key);

            return '' === $value ? self::SPEC[$key][0] : $value;
        };

        $c = new self();
        $c->sources = self::list($raw('BLOCKLIST_SOURCES'));
        $c->countries = array_values(array_unique(array_map('strtolower', self::list($raw('BLOCK_COUNTRIES')))));
        foreach ($c->countries as $cc) {
            if (!preg_match('/^[a-z]{2}$/', $cc)) {
                throw new \InvalidArgumentException("BLOCK_COUNTRIES: invalid country code \"$cc\" (expected ISO 3166-1 alpha-2, e.g. \"de\")");
            }
        }
        if ([] === $c->sources && [] === $c->countries) {
            throw new \InvalidArgumentException('Nothing to do: BLOCKLIST_SOURCES and BLOCK_COUNTRIES are both empty');
        }

        $c->countryIpv4 = self::bool($get('COUNTRY_IPV4'), 'COUNTRY_IPV4');
        $c->countryIpv6 = self::bool($get('COUNTRY_IPV6'), 'COUNTRY_IPV6');
        if ([] !== $c->countries && !$c->countryIpv4 && !$c->countryIpv6) {
            throw new \InvalidArgumentException('COUNTRY_IPV4 and COUNTRY_IPV6 are both false, nothing to download for BLOCK_COUNTRIES');
        }
        $c->countryAggregated = self::bool($get('COUNTRY_AGGREGATED'), 'COUNTRY_AGGREGATED');
        $c->ipdenyBaseUrl = rtrim($get('IPDENY_BASE_URL'), '/');
        if (!preg_match('#^https?://#i', $c->ipdenyBaseUrl)) {
            throw new \InvalidArgumentException('IPDENY_BASE_URL must be an http(s) URL');
        }

        $c->cacheDir = rtrim($get('CACHE_DIR'), '/') ?: '/';
        $c->cacheTtl = self::ttl($get('CACHE_TTL'), 'CACHE_TTL');
        $c->sourceCacheTtl = self::ttl($get('SOURCE_CACHE_TTL'), 'SOURCE_CACHE_TTL');

        $c->outputDir = rtrim($get('OUTPUT_DIR'), '/') ?: '/';
        $c->outputName = $get('OUTPUT_NAME');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $c->outputName) || str_starts_with($c->outputName, '.')) {
            throw new \InvalidArgumentException('OUTPUT_NAME may only contain letters, digits, ".", "_" and "-"');
        }
        $c->outputFormats = array_values(array_unique(array_map('strtolower', self::list($get('OUTPUT_FORMATS')))));
        foreach ($c->outputFormats as $format) {
            if (!in_array($format, self::FORMATS, true)) {
                throw new \InvalidArgumentException("OUTPUT_FORMATS: unknown format \"$format\" (supported: ".implode(', ', self::FORMATS).')');
            }
        }
        if ([] === $c->outputFormats) {
            throw new \InvalidArgumentException('OUTPUT_FORMATS must not be empty');
        }
        $c->outputGzip = self::bool($get('OUTPUT_GZIP'), 'OUTPUT_GZIP');
        $c->outputManifest = self::bool($get('OUTPUT_MANIFEST'), 'OUTPUT_MANIFEST');
        $c->mergeRanges = self::bool($get('MERGE_RANGES'), 'MERGE_RANGES');
        $c->p2bVersion = self::int($get('P2B_VERSION'), 'P2B_VERSION', 1, 3);
        $c->httpTimeout = self::int($get('HTTP_TIMEOUT'), 'HTTP_TIMEOUT', 1, 3600);
        $c->httpRetries = self::int($get('HTTP_RETRIES'), 'HTTP_RETRIES', 0, 20);
        $c->userAgent = $get('HTTP_USER_AGENT');
        $c->failOnSourceError = self::bool($get('FAIL_ON_SOURCE_ERROR'), 'FAIL_ON_SOURCE_ERROR');
        $c->logLevel = strtolower($get('LOG_LEVEL'));
        if (!isset(Logger::LEVELS[$c->logLevel])) {
            throw new \InvalidArgumentException('LOG_LEVEL must be one of debug, info, warn, error');
        }

        return $c;
    }

    /**
     * Splits a list value on commas, semicolons, whitespace and newlines.
     *
     * @return list<string>
     */
    public static function list(string $value): array
    {
        return preg_split('/[\s,;]+/', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function bool(string $value, string $name): bool
    {
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        throw new \InvalidArgumentException("$name: expected a boolean (true/false), got \"$value\"");
    }

    public static function int(string $value, string $name, int $min, int $max): int
    {
        if (!preg_match('/^-?\d+$/', trim($value))) {
            throw new \InvalidArgumentException("$name: expected an integer, got \"$value\"");
        }
        $n = (int) $value;
        if ($n < $min || $n > $max) {
            throw new \InvalidArgumentException("$name: must be between $min and $max, got $n");
        }

        return $n;
    }

    /**
     * Parses "3600", "90m", "12h", "7d", "2w" into seconds.
     */
    public static function ttl(string $value, string $name): int
    {
        if (!preg_match('/^(\d+)\s*([smhdw]?)$/i', trim($value), $m)) {
            throw new \InvalidArgumentException("$name: expected a duration like \"3600\", \"12h\" or \"7d\", got \"$value\"");
        }
        $factor = match (strtolower($m[2])) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
            default => 1,
        };

        return (int) $m[1] * $factor;
    }

    public static function help(): string
    {
        $out = "torrent-blocklist - builds qBittorrent IP filter files (.p2p, .dat, .p2b)\n\n"
            ."Usage: generate.php [--help]\n\nConfiguration is read from environment variables:\n\n";
        foreach (self::SPEC as $name => [$default, $description]) {
            $out .= sprintf("  %s\n      default: %s\n      %s\n\n", $name, '' === $default ? '(empty)' : $default, wordwrap($description, 90, "\n      "));
        }

        return $out;
    }
}
