<?php

namespace Utopia\Span\Storage;

use Utopia\Span\Span;

interface Storage
{
    /**
     * Get the current span for this context
     */
    public function get(): ?Span;

    /**
     * Set the current span for this context
     */
    public function set(?Span $span): void;
}
