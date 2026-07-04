<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

interface Asynchronous
{
    /**
     * Hands a message off to be published in the background, returning without
     * waiting for the broker to accept it.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool;
}
