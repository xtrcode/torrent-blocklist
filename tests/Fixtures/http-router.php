<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server (see Blocklist\Tests\Support\TestHttpServer).
 *   /missing        404
 *   /flaky?fail=N   500 for the first N requests of this URL, then "7.0.0.0/8"
 *   /stale          200 on the first request, 500 afterwards
 *   /redirect       302 to /de.zone
 *   anything else   static file below the document root with ETag + Last-Modified,
 *                   answers If-None-Match with 304, 404 when the file does not exist
 * Request counters live in the document root (one state file per URL).
 */

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$root = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : sys_get_temp_dir();
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$stateFile = $root.'/.state-'.md5($uri);
$hits = is_file($stateFile) ? (int) file_get_contents($stateFile) : 0;
file_put_contents($stateFile, (string) ($hits + 1));
$fail = is_numeric($_GET['fail'] ?? null) ? (int) $_GET['fail'] : 1;

if ('/missing' === $path) {
    http_response_code(404);
    echo "not found\n";

    return true;
}

if ('/flaky' === $path || '/stale' === $path) {
    $failing = '/flaky' === $path ? $hits < $fail : $hits > 0;
    if ($failing) {
        http_response_code(500);
        echo "boom\n";

        return true;
    }
    header('Content-Type: text/plain');
    echo '/flaky' === $path ? "7.0.0.0/8\n" : "8.0.0.0/8\n";

    return true;
}

if ('/redirect' === $path) {
    header('Location: /de.zone', true, 302);

    return true;
}

$file = realpath($root.$path);
$realRoot = realpath($root);
if (false === $file || false === $realRoot || !str_starts_with($file, $realRoot.'/') || !is_file($file)) {
    http_response_code(404);

    return true;
}
$etag = '"'.md5_file($file).'"';
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s', (int) filemtime($file)).' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);

    return true;
}
header('Content-Type: text/plain');
readfile($file);

return true;
