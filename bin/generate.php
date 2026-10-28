#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Entry point: builds the qBittorrent blocklist files from environment configuration.
 * Exit codes: 0 = success, 1 = build failed, 2 = invalid configuration.
 */

use Blocklist\Config;
use Blocklist\Generator;
use Blocklist\Logger;

$autoload = __DIR__.'/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are missing, run \"composer install\" first.\n");
    exit(1);
}
require $autoload;

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    fwrite(STDOUT, Config::help());
    exit(0);
}
if ([] !== $arguments) {
    fwrite(STDERR, 'Unknown argument(s): '.implode(' ', $arguments)."\nAll configuration is passed via environment variables, see --help.\n");
    exit(2);
}

try {
    $config = Config::fromEnv(getenv());
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Configuration error: '.$e->getMessage()."\n");
    exit(2);
}

exit((new Generator($config, new Logger($config->logLevel)))->run());
