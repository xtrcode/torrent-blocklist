<?php

declare(strict_types=1);

namespace Blocklist\Tests\Support;

/**
 * PHP built-in web server with tests/Fixtures/http-router.php, serving a temporary document root.
 */
final class TestHttpServer
{
    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly string $docRoot,
        public readonly string $baseUrl,
    ) {
    }

    public static function start(): self
    {
        $docRoot = sys_get_temp_dir().'/blocklist-http-'.bin2hex(random_bytes(6));
        if (!mkdir($docRoot, 0777, true)) {
            throw new \RuntimeException("Cannot create $docRoot");
        }

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $port = random_int(20000, 40000);
            $process = proc_open(
                [\PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docRoot, \dirname(__DIR__).'/Fixtures/http-router.php'],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
            );
            if (false === $process) {
                continue;
            }
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && proc_get_status($process)['running']) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (false !== $socket) {
                    fclose($socket);

                    return new self($process, $docRoot, "http://127.0.0.1:$port");
                }
                usleep(50_000);
            }
            proc_terminate($process);
            proc_close($process);
        }
        throw new \RuntimeException('Cannot start the built-in web server');
    }

    /**
     * Places a file below the document root, creating directories as needed.
     */
    public function put(string $path, string $content): void
    {
        $file = $this->docRoot.'/'.ltrim($path, '/');
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->docRoot);
    }
}
