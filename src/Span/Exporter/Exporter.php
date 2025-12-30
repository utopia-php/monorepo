<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

interface Exporter
{
    /**
     * Export a span to the backend
     */
    public function export(Span $span): void;
}
