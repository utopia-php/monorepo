<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Baseline shared by every package. Paths resolve against the package being
// checked (its CWD), so `bin/monorepo check` can point rector here with
// `-c ../../rector.php`. A package's own rector.php overrides this entirely.
return RectorConfig::configure()
    ->withPaths([
        getcwd() . '/src',
        getcwd() . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    );
