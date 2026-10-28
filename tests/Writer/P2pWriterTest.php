<?php

declare(strict_types=1);

namespace Blocklist\Tests\Writer;

use Blocklist\Ip\RangeSet;
use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use Blocklist\Writer\Label;
use Blocklist\Writer\P2pWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(P2pWriter::class)]
#[CoversClass(Label::class)]
final class P2pWriterTest extends TestCase
{
    use TempDirectoryTrait;

    public function testOutputIsParsedBackIdentically(): void
    {
        $dir = $this->createTempDirectory();
        $set = new RangeSet();
        $set->add(Helpers::range('1.0.0.0', '1.0.0.255', 'Plain'));
        $set->add(Helpers::range('2.0.0.0', '2.0.0.1', "With: colon\tand\ttabs"));
        $set->add(Helpers::range('2001:db8::', '2001:db8::ff', 'v6'));
        $set->add(Helpers::range('3.0.0.0', '3.0.0.0', ''));

        $result = (new P2pWriter())->write("$dir/out.p2p", $set->ranges(), ['header line', 'second']);

        self::assertSame(4, $result->written);
        self::assertSame(0, $result->omitted);
        $content = Helpers::read("$dir/out.p2p");
        self::assertStringStartsWith("# header line\n# second\n", $content);
        self::assertStringContainsString("With colon and tabs:2.0.0.0-2.0.0.1\n", $content, 'label sanitised');
        self::assertStringContainsString("Blocked:3.0.0.0-3.0.0.0\n", $content, 'empty label replaced');
        self::assertSame(
            ['1.0.0.0-1.0.0.255 Plain', '2.0.0.0-2.0.0.1 With colon and tabs', '3.0.0.0-3.0.0.0 Blocked', '2001:db8::-2001:db8::ff v6'],
            Helpers::readFile("$dir/out.p2p"),
        );
        self::assertFileDoesNotExist("$dir/out.p2p.tmp");
    }

    public function testLabelCleaning(): void
    {
        self::assertSame('a b', Label::clean("  a \x00\n b  "));
        self::assertSame(Label::FALLBACK, Label::clean(" \t "));
    }
}
