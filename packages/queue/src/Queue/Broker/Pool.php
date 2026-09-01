<?php

namespace Utopia\Queue\Broker;

use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Pool implements Publisher, Consumer
{
    public function __construct(
        private ?UtopiaPool $publisher = null,
        private ?UtopiaPool $consumer = null,
    ) {}

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    /**
     * {@see Redis::reap()} — requires the pooled publisher to be a broker that
     * implements it.
     */
    public function reap(Queue $queue, int $olderThan = 90000, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): int
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        return $this->delegate($this->consumer, __FUNCTION__, \func_get_args());
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->delegate($this->consumer, __FUNCTION__, \func_get_args());
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->delegate($this->consumer, __FUNCTION__, \func_get_args());
    }

    /**
     * Run idle upkeep across both pools.
     *
     * Safe to call at any time, including while a receive loop is running: the
     * sweep reaches only resources sitting idle in the pool, never the one a
     * caller currently holds. That is the whole difference from calling
     * {@see Nats::tick()} directly, which needs exclusive access.
     *
     * A pooled broker is the case the keepalive exists for — a publisher slot
     * can sit untouched for longer than the server's ping deadline and be
     * reaped without anyone noticing until the next publish.
     */
    public function maintain(): void
    {
        $this->publisher?->maintain();

        // Distinct pools; a broker configured with only one leaves the other null.
        if ($this->consumer instanceof UtopiaPool && $this->consumer !== $this->publisher) {
            $this->consumer->maintain();
        }
    }

    public function close(): void
    {
        // TODO: Implement closing all connections in the pool
    }

    /**
     * @param array<mixed> $args
     */
    protected function delegate(?UtopiaPool $pool, string $method, array $args): mixed
    {
        return $pool?->use(fn(Publisher|Consumer $adapter) => $adapter->$method(...$args));
    }
}
