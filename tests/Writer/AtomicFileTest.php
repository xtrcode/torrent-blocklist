<?php

declare(strict_types=1);

namespace Blocklist\Tests\Writer;

use Blocklist\Tests\Support\Helpers;
use Blocklist\Tests\Support\TempDirectoryTrait;
use Blocklist\Writer\AtomicFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtomicFile::class)]
final class AtomicFileTest extends TestCase
{
    use TempDirectoryTrait;

    public function testCommitsOnSuccessAndCreatesMissingDirectories(): void
    {
        $dir = $this->createTempDirectory();
        $result = AtomicFile::write("$dir/sub/out.txt", static function (AtomicFile $file): string {
            $file->append('hello ');
            $file->append('world');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame('hello world', Helpers::read("$dir/sub/out.txt"));
        self::assertFileDoesNotExist("$dir/sub/out.txt.tmp");
    }

    public function testKeepsThePreviousFileWhenWritingFails(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents("$dir/out.txt", 'previous');
        $lines = (static function (): \Generator {
            yield "first\n";

            throw new \LogicException('source broke mid-write');
        })();

        try {
            AtomicFile::write("$dir/out.txt", static function (AtomicFile $file) use ($lines): int {
                foreach ($lines as $line) {
                    $file->append($line);
                }

                return 1;
            });
            self::fail('exception expected');
        } catch (\LogicException $e) {
            self::assertSame('source broke mid-write', $e->getMessage());
        }

        self::assertSame('previous', Helpers::read("$dir/out.txt"));
        self::assertFileDoesNotExist("$dir/out.txt.tmp");
    }
}
