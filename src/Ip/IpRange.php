<?php

declare(strict_types=1);

namespace Blocklist\Ip;

final readonly class IpRange
{
    /**
     * @param IpMath::V4|IpMath::V6 $family
     * @param string                $start  binary address (4 or 16 bytes)
     * @param string                $end    binary address (4 or 16 bytes)
     */
    public function __construct(
        public int $family,
        public string $start,
        public string $end,
        public string $name,
    ) {
    }

    public function isV4(): bool
    {
        return IpMath::V4 === $this->family;
    }

    public function startText(): string
    {
        return IpMath::format($this->start);
    }

    public function endText(): string
    {
        return IpMath::format($this->end);
    }
}
