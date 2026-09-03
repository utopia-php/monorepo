<?php

declare(strict_types=1);

namespace Swoole\Coroutine;

/**
 * Run a coroutine callback
 */
function run(callable $callback): void
{
    $callback();
}
