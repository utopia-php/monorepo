<?php

declare(strict_types=1);

use Rector\Config\RectorConfigBuilder;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;

/** @var RectorConfigBuilder $config */
$config = require __DIR__ . '/../../rector.php';

return $config->withSkip([
    // Strips the `@var T` generic hint createMeter() needs for phpstan (level max).
    RemoveNonExistingVarAnnotationRector::class,
]);
