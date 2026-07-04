<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

interface Synchronous
{
    /**
     * Publishes a message onto the queue, blocking until the broker accepts it.
     */
    public function publish(Queue $queue, array $payload, bool $priority = false): bool;

    /**
     * Retries failed jobs.
     */
    public function retry(Queue $queue, ?int $limit = null): void;

    /**
     * Returns the amount of pending messages in the queue.
     */
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int;
}
