<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

/**
 * A publisher that hands messages to the broker synchronously: publish() blocks
 * until the broker accepts the message and returns whether it did. Brokers such
 * as Redis and Pool implement this directly; Broker\Background wraps one to add
 * background dispatch.
 */
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
