# torrent-blocklist

Ready-to-use IP filter files for [qBittorrent](https://www.qbittorrent.org/) in **every format qBittorrent supports** (`.p2p`, `.dat`, `.p2b`), built from [waelisa/Best-blocklist](https://github.com/waelisa/Best-blocklist) and, optionally, the complete address space of whole countries from [ipdeny.com](https://www.ipdeny.com/ipblocks/).

The files are generated inside a Docker container (PHP 8.4 on the official `php:cli-alpine` image, no runtime dependencies besides the Composer autoloader), configured entirely through environment variables, and rebuilt daily by a GitHub Action that commits the result back into [`dist/`](dist/).

## Download

| File | Format | Notes |
| --- | --- | --- |
| [`dist/blocklist.p2p`](dist/blocklist.p2p) | PeerGuardian text | IPv4 + IPv6, human readable |
| [`dist/blocklist.dat`](dist/blocklist.dat) | eMule `ipfilter.dat` | IPv4 + IPv6 |
| [`dist/blocklist.p2b`](dist/blocklist.p2b) | PeerGuardian binary v3 | IPv4 only (format limitation), smallest file |
| [`dist/blocklist.json`](dist/blocklist.json) | manifest | sources, range counts, SHA-256 of each file |

Raw URL pattern for scripts: `https://raw.githubusercontent.com/xtrcode/torrent-blocklist/main/dist/blocklist.p2p`

### Use in qBittorrent

1. Download one of the files (any format, `.p2p` is the usual choice).
2. qBittorrent → *Options* → *Connection* → *IP Filtering* → tick *Filter path* and select the file.
3. Click *Apply*. The log (*View* → *Log*) shows how many rules were loaded.

qBittorrent does not re-download filters by itself. Re-fetch the file from time to time (cron, Task Scheduler, …) and restart qBittorrent or toggle the filter to reload it.

## What is in the list

* **Base list:** [Best-blocklist](https://github.com/waelisa/Best-blocklist) by Wael Isa (MIT). It aggregates several well-known lists (Naunter, mxdpeep, eMule Security) and removes ranges that cause false positives on seedboxes. ~485,000 entries, IPv4.
* **Optional country blocks:** zone files from [ipdeny.com](https://www.ipdeny.com/ipblocks/) (IPv4 and IPv6, aggregated) for every country listed in `BLOCK_COUNTRIES`. Not enabled by default; blocking whole countries is a policy decision you make in [`blocklist.env`](blocklist.env).
* Everything is deduplicated and, by default, overlapping/adjacent ranges are merged. Labels of the first range survive a merge.

## Run it yourself

```bash
git clone https://github.com/xtrcode/torrent-blocklist.git && cd torrent-blocklist

# build the image and generate dist/blocklist.{p2p,dat,p2b,json}
docker compose run --rm blocklist

# same without compose
docker build -t torrent-blocklist .
docker run --rm --user "$(id -u):$(id -g)" \
  --env-file blocklist.env \
  -e BLOCK_COUNTRIES=cn,ru \
  -v "$PWD/cache:/cache" -v "$PWD/dist:/output" \
  torrent-blocklist

docker run --rm torrent-blocklist help   # list all variables
```

`/cache` holds downloaded sources and country zones, `/output` receives the generated files. Mount both.

## Configuration

All settings are environment variables. The committed [`blocklist.env`](blocklist.env) is used by `docker compose` and by the GitHub Action, so editing that file is the normal way to configure the build. Docker's `--env-file` takes values literally, so do not quote them.

| Variable | Default | Meaning |
| --- | --- | --- |
| `BLOCKLIST_SOURCES` | Best-blocklist zip URL | URLs or paths (inside the container) of base lists, separated by commas, whitespace or newlines. Formats: PeerGuardian `.p2p`, eMule `.dat`, plain CIDR/IP lists; packed as `.zip` or `.gz` is fine. Format is picked by extension or sniffed. Empty = country-only list. |
| `BLOCK_COUNTRIES` | *(empty)* | ISO 3166-1 alpha-2 codes to block completely, e.g. `cn,ru,kp`. |
| `COUNTRY_IPV4` / `COUNTRY_IPV6` | `true` / `true` | Which address families to fetch for the countries. Countries without an IPv6 allocation produce a warning, not an error. |
| `COUNTRY_AGGREGATED` | `true` | Use ipdeny's aggregated (pre-merged) zone files. |
| `IPDENY_BASE_URL` | `https://www.ipdeny.com` | Change to use a mirror. |
| `CACHE_DIR` | `/cache` | Download cache. |
| `CACHE_TTL` | `7d` | How long a country zone is reused before ipdeny.com is asked again (`s`, `m`, `h`, `d`, `w`; `0` = revalidate every run). |
| `SOURCE_CACHE_TTL` | `0` | Same for the base lists. `0` means a conditional request (ETag / Last-Modified) on every run, so unchanged lists are not transferred again. |
| `OUTPUT_DIR` | `/output` | Target directory. |
| `OUTPUT_NAME` | `blocklist` | Base file name. |
| `OUTPUT_FORMATS` | `p2p,dat,p2b` | Any subset. |
| `OUTPUT_GZIP` | `false` | Additionally write `.gz` copies. |
| `OUTPUT_MANIFEST` | `true` | Write `<name>.json`. |
| `MERGE_RANGES` | `true` | Merge overlapping and adjacent ranges. |
| `P2B_VERSION` | `3` | PeerGuardian binary version `1`, `2` or `3` (qBittorrent reads all). |
| `HTTP_TIMEOUT` / `HTTP_RETRIES` | `120` / `3` | Download timeout (seconds) and retries with back-off. |
| `HTTP_USER_AGENT` | `torrent-blocklist/1.0 (+repo URL)` | Sent with every download. |
| `FAIL_ON_SOURCE_ERROR` | `true` | Exit 1 if a source cannot be loaded and no cached copy exists. `false` skips the source with a warning. |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error` (stderr; the summary goes to stdout). |

### Caching

Every download lands in `CACHE_DIR` under a path mirroring the URL (for example `cache/www.ipdeny.com/ipblocks/data/aggregated/de-aggregated.zone`) with a `.meta.json` sidecar holding the fetch time, ETag and Last-Modified.

* A country zone younger than `CACHE_TTL` is used without touching the network.
* Once it is older, the container sends a conditional request. A `304 Not Modified` just refreshes the timestamp.
* If a download fails and a cached copy exists, the stale copy is used and a warning is logged, so a hiccup at ipdeny.com or GitHub never produces a truncated list.
* A `404` for one address family (for example no IPv6 zone for a country) is a warning. A `404` for all families means the country code is wrong and is treated as a source error.

In GitHub Actions the cache directory is persisted between runs with `actions/cache`.

## GitHub Action

[`.github/workflows/build-blocklist.yml`](.github/workflows/build-blocklist.yml) runs daily at 04:17 UTC, on manual dispatch, and whenever the generator or `blocklist.env` changes on `main`:

1. builds the `ci` image and runs `composer check` (PHP-CS-Fixer, PHPStan, PHPUnit),
2. builds the `runtime` image and restores the download cache,
3. runs the container with `--env-file blocklist.env`,
4. commits `dist/` **only if the generated files changed** (the outputs contain no timestamps, so an unchanged upstream list results in no commit).

Pull requests run steps 1 to 3 without committing.

To run your own variant: fork the repository, edit `blocklist.env` (e.g. set `BLOCK_COUNTRIES`), push. GitHub disables scheduled workflows in forks until you enable them once under *Actions*. The workflow needs the default `GITHUB_TOKEN` with write permission, which it requests via `permissions: contents: write`.

## Output formats

| | `.p2p` | `.dat` | `.p2b` |
| --- | --- | --- | --- |
| Layout | `Label:1.2.3.0-1.2.3.255` | `001.002.003.000 - 001.002.003.255 , 000 , Label` | binary, big-endian, labels deduplicated (v3) |
| IPv6 | yes | yes | no |
| Comments | `#` header | `#` header | none |

The writers follow qBittorrent's parser (`src/base/bittorrent/filterparserthread.cpp`): the range separator in `.p2p` is the last dash, labels never contain colons, `.dat` ranges use access level `000`, and `.p2b` addresses are stored in network byte order.

## Development

Everything runs in Docker, no local PHP or Composer is needed. The `dev` compose service is the `toolchain` stage of the [Dockerfile](Dockerfile) (PHP 8.4 + Composer) with the working copy mounted.

```bash
docker compose run --rm dev composer install     # once, installs vendor/ into the working copy
docker compose run --rm dev composer check       # everything CI runs: cs + phpstan + test
docker compose run --rm dev composer cs:fix      # apply the Symfony coding style
docker compose run --rm dev composer test -- --filter RangeSet
docker compose run --rm dev composer generate    # run the generator from source with blocklist.env
```

| Composer script | Tool | Configuration |
| --- | --- | --- |
| `composer test` | PHPUnit 13 | [phpunit.xml.dist](phpunit.xml.dist), strict: warnings, notices, deprecations and risky tests fail |
| `composer phpstan` | PHPStan 2, level `max`, with the PHPUnit extension | [phpstan.neon.dist](phpstan.neon.dist) |
| `composer cs` / `cs:fix` | PHP-CS-Fixer, `@Symfony` rule set | [.php-cs-fixer.dist.php](.php-cs-fixer.dist.php) |
| `composer check` | all three, in this order | |

Local overrides (`phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.php`) are git-ignored. Tool caches live in `var/`.

### Docker stages

| Stage | Contents | Used by |
| --- | --- | --- |
| `toolchain` | PHP + Composer | `docker compose run dev` |
| `ci` | toolchain + all dependencies + source and tests baked in | GitHub Action (`composer check`) |
| `runtime` (default) | PHP + `bin/`, `src/`, production autoloader | generating the lists |

### Layout

```
bin/generate.php       entry point (reads env, runs the Generator)
src/Config.php         environment variables, defaults and validation
src/Generator.php      orchestrates fetch, parse, merge, write
src/Http/              FileCache + Downloader (conditional requests, retries, stale fallback)
src/Parser/            P2P / DAT / CIDR line parsers, SourceReader (zip, gz, format sniffing)
src/Ip/                binary IP math, RangeSet (dedupe, sort, merge)
src/Writer/            P2P, DAT, P2B and manifest writers (atomic writes)
src/Report/            build report, summary and formatting helpers
tests/                 PHPUnit tests mirroring src/, incl. a local HTTP server acting as ipdeny mirror
```

`.zip` sources are read through `PharData`, so the official PHP image needs no extra extensions.

## Credits and licenses

* Code in this repository: MIT, see [LICENSE](LICENSE).
* [Best-blocklist](https://github.com/waelisa/Best-blocklist) © Wael Isa, MIT.
* Country zones © [ipdeny.com](https://www.ipdeny.com/ipblocks/), redistribution permitted per their usage policy; please link back if you redistribute.

A blocklist is one layer of protection, not a guarantee. It will also block some legitimate peers.
