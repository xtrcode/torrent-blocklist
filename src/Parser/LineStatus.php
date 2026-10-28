<?php

declare(strict_types=1);

namespace Blocklist\Parser;

/**
 * Outcome of a line that does not produce a blocked range.
 */
enum LineStatus
{
    /** The line cannot be parsed. */
    case Malformed;

    /** The line is valid but blocks nothing (e.g. eMule access level >= 128). */
    case Ignored;
}
