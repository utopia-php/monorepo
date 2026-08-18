<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Schedule\Lease;

final class FakeLease implements Lease
{
    public bool $released = false;

    public int $acquireAttempts = 0;

    /**
     * @param \Closure(): bool $onAcquire
     * @param \Closure(): bool $onRenew
     */
    public function __construct(
        private readonly \Closure $onAcquire,
        private readonly \Closure $onRenew,
    ) {}

    #[\Override]
    public function acquire(): bool
    {
        ++$this->acquireAttempts;

        return ($this->onAcquire)();
    }

    #[\Override]
    public function renew(): bool
    {
        return ($this->onRenew)();
    }

    #[\Override]
    public function release(): void
    {
        $this->released = true;
    }
}
