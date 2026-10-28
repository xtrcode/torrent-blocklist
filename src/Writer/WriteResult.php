<?php

declare(strict_types=1);

namespace Blocklist\Writer;

final readonly class WriteResult
{
    /**
     * @param int $written ranges written
     * @param int $omitted ranges the format cannot represent (IPv6 in .p2b)
     */
    public function __construct(public int $written, public int $omitted = 0)
    {
    }
}
