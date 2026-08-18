<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * In-process watermark storage. Coverage survives ticks but not
 * restarts; use shared storage in production.
 */
final class MemoryState implements State
{
    private ?string $value = null;

    #[\Override]
    public function get(): ?string
    {
        return $this->value;
    }

    #[\Override]
    public function put(string $value): void
    {
        $this->value = $value;
    }
}
