<?php

declare(strict_types=1);

namespace Blocklist\Tests;

use Blocklist\Logger;
use Blocklist\Tests\Support\Helpers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Logger::class)]
final class LoggerTest extends TestCase
{
    public function testFiltersByLevelAndCountsWarningsAndErrors(): void
    {
        $stream = Helpers::memoryStream();
        $logger = new Logger('warn', $stream);
        $logger->debug('hidden debug');
        $logger->info('hidden info');
        $logger->warn('shown warning');
        $logger->error('shown error');

        $output = Helpers::streamContents($stream);
        self::assertStringNotContainsString('hidden', $output);
        self::assertMatchesRegularExpression('/^\d\d:\d\d:\d\d WARN  shown warning$/m', $output);
        self::assertMatchesRegularExpression('/^\d\d:\d\d:\d\d ERROR shown error$/m', $output);
        self::assertSame(1, $logger->warnings);
        self::assertSame(1, $logger->errors);
    }

    public function testUnknownLevelFallsBackToInfo(): void
    {
        $stream = Helpers::memoryStream();
        $logger = new Logger('chatty', $stream);
        $logger->debug('hidden');
        $logger->info('visible');

        self::assertStringContainsString('visible', Helpers::streamContents($stream));
        self::assertStringNotContainsString('hidden', Helpers::streamContents($stream));
    }
}
