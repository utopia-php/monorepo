<?php

declare(strict_types=1);

namespace Utopia\Queue\Connection;

interface Atomic
{
    public function supportsAtomic(): bool;

    /**
     * Evaluate a Lua script. Keys must be the first $keyCount values in
     * $arguments, followed by script arguments.
     *
     * @param array<mixed> $arguments
     */
    public function evaluate(string $script, array $arguments = [], int $keyCount = 0): mixed;
}
