<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        codeQuality: true,
        codingStyle: true,
        deadCode: true,
        earlyReturn: true,
        instanceOf: true,
        privatization: true,
        phpunitCodeQuality: true,
        typeDeclarations: true,
    );
