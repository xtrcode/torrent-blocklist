<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/bin', __DIR__.'/src', __DIR__.'/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/cache/.php-cs-fixer.cache');
