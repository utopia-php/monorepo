<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Exports spans as newline-delimited JSON (NDJSON).
 *
 * Writes error spans to stderr and non-error spans to stdout.
 * Error stacktraces are truncated to keep output readable.
 */
class Stdout implements Exporter
{
    /**
     * Create a new Stdout exporter.
     *
     * @param int $maxTraceFrames Maximum stacktrace frames to include for errors
     */
    public function __construct(
        private int $maxTraceFrames = 3
    ) {}

    public function export(Span $span): void
    {
        $data = $span->getAttributes();
        $error = $span->getError();

        if ($error !== null) {
            $data['error.type'] = $error::class;
            $data['error.message'] = $error->getMessage();
            $data['error.code'] = $error->getCode();
            $data['error.file'] = $error->getFile();
            $data['error.line'] = $error->getLine();

            $trace = $error->getTrace();
            $limited = array_slice($trace, 0, $this->maxTraceFrames);
            $data['error.trace'] = array_map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
            ], $limited);

            if (count($trace) > $this->maxTraceFrames) {
                $data['error.trace_truncated'] = count($trace) - $this->maxTraceFrames;
            }
        }

        $output = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($output === false) {
            return;
        }

        $stream = $error !== null ? STDERR : STDOUT;

        fwrite($stream, $output . PHP_EOL);
    }
}
