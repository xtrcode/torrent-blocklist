<?php

declare(strict_types=1);

namespace Blocklist\Http;

enum FetchStatus
{
    /** A local path, no download involved. */
    case Local;

    /** Cache entry younger than the TTL, no request sent. */
    case Cached;

    /** Conditional request answered with "304 Not Modified". */
    case Revalidated;

    /** Fresh download. */
    case Downloaded;

    /** Download failed, an older cache entry is used instead. */
    case Stale;
}
