<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../tests.php';

use Utopia\Queue\Broker\KubernetesJobEnvelope;

try {
    $message = KubernetesJobEnvelope::read();
    handleRequest($message);
    fwrite(STDOUT, "Job {$message->getPid()} completed\n");
    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, "Job failed: {$error->getMessage()}\n");
    exit(1);
}
