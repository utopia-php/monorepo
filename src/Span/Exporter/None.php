<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Null exporter that discards all spans
 * Useful for testing or disabling tracing
 */
class None implements Exporter
{
    public function export(Span $span): void
    {
        // Intentionally empty - discards all spans
    }
}
