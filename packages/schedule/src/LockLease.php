<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Utopia\Lock\Distributed;

/**
 * Lease backed by a utopia-php/lock distributed lock. Configure the
 * lock's TTL to at least a few tick intervals so a paused leader loses
 * the lease before its absence leaves a gap larger than the lookback.
 */
final readonly class LockLease implements Lease
{
    public function __construct(private Distributed $lock) {}

    #[\Override]
    public function acquire(): bool
    {
        return $this->lock->tryAcquire();
    }

    #[\Override]
    public function renew(): bool
    {
        return $this->lock->refresh();
    }

    #[\Override]
    public function release(): void
    {
        $this->lock->release();
    }
}
