<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

/**
 * A publisher that accepts messages without waiting for the broker: enqueue()
 * hands the message off for background delivery and returns immediately. The
 * bool reports whether the message was accepted for dispatch, not that it was
 * published. Implementations decide how the deferred work runs — Broker\Background
 * drains a Swoole channel on reader coroutines.
 */
interface Asynchronous
{
    /**
     * Hands a message off to be published in the background, returning without
     * waiting for the broker to accept it.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool;
}
