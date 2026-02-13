<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Exports spans as colourful, human-readable multi-line output.
 *
 * Designed for local development. Writes error spans to stderr
 * and non-error spans to stdout.
 */
readonly class Pretty implements Exporter
{
    private const ESC = "\033[";

    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";

    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";

    /**
     * @param int $maxTraceFrames Maximum stacktrace frames to include for errors
     * @param int $width Line width for the separator
     */
    public function __construct(
        private int $maxTraceFrames = 3,
        private int $width = 60,
    ) {
    }

    public function export(Span $span): void
    {
        $error = $span->getError();
        $hasError = $error instanceof \Throwable;
        $stream = $hasError ? STDERR : STDOUT;

        $lines = [];

        $lines[] = $this->header($span, $hasError);
        $lines[] = '';

        $attributes = [];
        foreach ($span->getAttributes() as $key => $value) {
            if (!str_starts_with($key, 'span.')) {
                $attributes[$key] = $value;
            }
        }

        $maxKeyLen = 0;
        foreach ($attributes as $key => $_) {
            $maxKeyLen = max($maxKeyLen, strlen($key));
        }

        foreach ($attributes as $key => $value) {
            $lines[] = $this->attribute($key, $value, $maxKeyLen);
        }

        if ($hasError) {
            if (count($attributes) > 0) {
                $lines[] = '';
            }

            $lines[] = $this->error($error);

            $trace = $error->getTrace();
            $limited = array_slice($trace, 0, $this->maxTraceFrames);

            foreach ($limited as $frame) {
                $file = $frame['file'] ?? 'unknown';
                $line = $frame['line'] ?? '?';
                $lines[] = self::DIM . "    at {$file}:{$line}" . self::RESET;
            }

            $remaining = count($trace) - $this->maxTraceFrames;
            if ($remaining > 0) {
                $lines[] = self::DIM . "    ... {$remaining} more" . self::RESET;
            }
        }

        $lines[] = '';
        $lines[] = self::DIM . str_repeat('─', $this->width) . self::RESET;

        fwrite($stream, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function header(Span $span, bool $hasError): string
    {
        $action = $span->getAction();
        $duration = $span->get('span.duration');
        $traceId = $span->get('span.trace_id');

        $actionColor = $hasError ? self::RED : self::GREEN;
        $actionStr = self::BOLD . $actionColor . $action . self::RESET;

        $parts = [$actionStr];

        if (is_float($duration)) {
            $durationStr = $this->formatDuration($duration);
            $durationColor = $this->durationColor($duration);
            $parts[] = $durationColor . $durationStr . self::RESET;
        }

        if (is_string($traceId)) {
            $short = substr($traceId, 0, 8);
            $parts[] = self::DIM . $short . self::RESET;
        }

        return implode(self::DIM . ' · ' . self::RESET, $parts);
    }

    private function attribute(string $key, string|int|float|bool|null $value, int $padTo): string
    {
        $padded = str_pad($key, $padTo);
        $keyStr = self::CYAN . $padded . self::RESET;
        $valStr = self::WHITE . $this->formatValue($value) . self::RESET;

        return "  {$keyStr} {$valStr}";
    }

    private function error(\Throwable $error): string
    {
        $type = $error::class;
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();

        return self::RED . self::BOLD . "  {$type}" . self::RESET
            . self::RED . ": {$message}" . self::RESET . PHP_EOL
            . self::DIM . "    at {$file}:{$line}" . self::RESET;
    }

    private function formatValue(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds >= 1.0) {
            return round($seconds, 2) . 's';
        }

        return round($seconds * 1000, 1) . 'ms';
    }

    private function durationColor(float $seconds): string
    {
        if ($seconds >= 1.0) {
            return self::RED;
        }

        if ($seconds >= 0.1) {
            return self::YELLOW;
        }

        return self::GREEN;
    }
}
