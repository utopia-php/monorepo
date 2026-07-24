<?php

declare(strict_types=1);

namespace Utopia\Queue\Connection;

interface Lua
{
    /**
     * Executes a Lua script with all keys declared before its arguments.
     *
     * @param list<string> $keys
     * @param list<int|string> $arguments
     */
    public function evaluate(string $script, array $keys = [], array $arguments = []): mixed;
}
