<?php

declare(strict_types=1);

use Rector\Config\RectorConfigBuilder;

/** @var RectorConfigBuilder $config */
$config = require __DIR__ . '/../../rector.php';

return $config->withPreparedSets(
    codingStyle: true,
    instanceOf: true,
    privatization: true,
);
