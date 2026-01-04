<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Interface for span exporters.
 *
 * Implement this to send spans to your observability backend.
 */
interface Exporter
{
    /**
     * Export a finished span.
     *
     * Called after Span::finish() for spans that pass the sampler.
     * Use $span->getAttributes() for metadata and $span->getError() for exceptions.
     *
     * @param Span $span The finished span to export
     */
    public function export(Span $span): void;
}
