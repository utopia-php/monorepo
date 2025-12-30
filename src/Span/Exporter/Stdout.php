<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Exports spans to stdout as JSON
 */
class Stdout implements Exporter
{
    public function export(Span $span): void
    {
        $output = json_encode($span->getAttributes(), JSON_UNESCAPED_SLASHES);

        if ($output === false) {
            return;
        }

        fwrite(STDOUT, $output . PHP_EOL);
    }
}
