<?php

declare(strict_types=1);

namespace Blocklist\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Creates throw-away directories that are removed after each test.
 */
trait TempDirectoryTrait
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function createTempDirectory(): string
    {
        $dir = sys_get_temp_dir().'/blocklist-test-'.bin2hex(random_bytes(6));
        if (!mkdir($dir, 0777, true)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $this->tempDirectories[] = $dir;

        return $dir;
    }

    #[After]
    protected function removeTempDirectories(): void
    {
        foreach ($this->tempDirectories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                \assert($item instanceof \SplFileInfo);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }
        $this->tempDirectories = [];
    }
}
