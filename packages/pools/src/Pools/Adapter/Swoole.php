<?php

declare(strict_types=1);

namespace Utopia\Pools\Adapter;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Lock;
use Utopia\Pools\Adapter;
use Utopia\Pools\Wakeup;

class Swoole extends Adapter
{
    /**
     * @var Channel<mixed>
     */
    protected Channel $pool;

    protected Lock $lock;

    /** @var Channel<bool> Coalesced capacity notifications, separate from idle resources. */
    private Channel $notifications;

    /** Shortest wait Swoole will honour without treating it as unbounded. */
    private const float POLL = 0.001;

    public function initialize(int $size): static
    {

        $this->pool = new Channel($size);
        $this->lock = new Lock();
        $this->notifications = new Channel(1);

        return $this;
    }

    public function push(mixed $connection): static
    {
        // Push connection to channel
        $this->pool->push($connection);
        $this->notify();

        return $this;
    }

    /**
     * Pop an item from the pool, waiting up to $timeout seconds.
     *
     * @return mixed|false The pooled value, or false if the timeout expired.
     */
    public function pop(float $timeout): mixed
    {
        // Swoole reads a non-positive channel timeout as "wait forever", the exact
        // opposite of a zero budget, so clamp to a single short poll instead.
        if ($this->count() > 0) {
            if ($this->notifications->length() > 0) {
                $this->notifications->pop(self::POLL);
            }
            return $this->pool->pop(self::POLL);
        }

        if ($this->notifications->pop($timeout > 0.0 ? $timeout : self::POLL) === false) {
            return false;
        }

        return $this->count() > 0 ? $this->pool->pop(self::POLL) : Wakeup::Capacity;
    }

    #[\Override]
    public function notify(): static
    {
        // Remember one change even if a waiter has not entered pop() yet.
        // Notifications never occupy or inflate the idle-resource queue.
        if ($this->notifications->length() === 0) {
            $this->notifications->push(true);
        }
        return $this;
    }

    /**
     * Another coroutine can change the idle contents during a blocking pop().
     * @phpstan-impure
     */
    public function count(): int
    {
        return (int) $this->pool->length();
    }

    /**
     * Executes a callback while holding a lock.
     *
     * The lock is acquired before invoking the callback and is always released
     * afterward, even if the callback throws an exception.
     *
     * @param  callable  $callback  Callback to execute within the critical section.
     * @return mixed The value returned by the callback.
     *
     * @throws \RuntimeException If the lock cannot be acquired within the timeout.
     */
    public function synchronized(callable $callback): mixed
    {
        $acquired = $this->lock->lock();

        if (! $acquired) {
            throw new \RuntimeException('Failed to acquire lock');
        }

        try {
            return $callback();
        } finally {
            $this->lock->unlock();
        }
    }
}
