<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\Config\RectorConfigBuilder;

/** @var RectorConfigBuilder $config */
$config = require __DIR__ . '/../../rector.php';

return $config->withSkip([
    ThrowWithPreviousExceptionRector::class,
]);
