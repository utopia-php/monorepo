<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Swoole\Coroutine;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

/**
 * Publisher decorator that can hand a publish off to a background Swoole
 * coroutine, so the caller isn't blocked on the broker round-trip.
 *
 * - publish() runs synchronously and returns the broker's result.
 * - enqueue() schedules the publish on a coroutine and returns immediately;
 *   with no coroutine runtime active it publishes synchronously instead.
 */
readonly class Async implements Publisher
{
    public function __construct(private Publisher $publisher) {}

    /**
     * Publish synchronously, blocking until the broker accepts the message.
     */
    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        return $this->publisher->enqueue($queue, $payload, $priority);
    }

    /**
     * Publish in the background. Returns true once the coroutine is scheduled;
     * outside a coroutine runtime it falls back to publishing synchronously.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        if (Coroutine::getCid() === -1) {
            return $this->publish($queue, $payload, $priority);
        }

        Coroutine::create(function () use ($queue, $payload, $priority): void {
            try {
                $this->publisher->enqueue($queue, $payload, $priority);
            } catch (\Throwable $error) {
                // Fire-and-forget: no caller to surface to, so log and move on.
                error_log('Uncaught error while publishing queue message: ' . $error->getMessage());
            }
        });

        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        $this->publisher->retry($queue, $limit);
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->publisher->getQueueSize($queue, $failedJobs);
    }
}
