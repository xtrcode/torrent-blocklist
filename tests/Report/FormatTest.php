<?php

declare(strict_types=1);

namespace Blocklist\Tests\Report;

use Blocklist\Report\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    public function testNumber(): void
    {
        self::assertSame('1,234,567', Format::number(1234567));
        self::assertSame('0', Format::number(0));
    }

    public function testBytes(): void
    {
        self::assertSame('512 B', Format::bytes(512));
        self::assertSame('1.5 KiB', Format::bytes(1536));
        self::assertSame('1.5 MiB', Format::bytes(1572864));
        self::assertSame('2.0 GiB', Format::bytes(2 * 1024 ** 3));
    }

    public function testDuration(): void
    {
        self::assertSame('59s', Format::duration(59));
        self::assertSame('2m', Format::duration(120));
        self::assertSame('1h 1m', Format::duration(3660));
        self::assertSame('7d', Format::duration(604800));
        self::assertSame('1d 2h', Format::duration(86400 + 7200 + 59));
    }
}
