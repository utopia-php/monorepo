<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Exports spans as newline-delimited JSON (NDJSON)
 *
 * Spans with errors are written to stderr, all others to stdout.
 */
class Stdout implements Exporter
{
    public function export(Span $span): void
    {
        $output = json_encode($span->getAttributes(), JSON_UNESCAPED_SLASHES);

        if ($output === false) {
            return;
        }

        $stream = $span->get('error.type') !== null ? STDERR : STDOUT;

        fwrite($stream, $output . PHP_EOL);
    }
}
