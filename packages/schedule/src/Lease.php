<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * Leader election for a scheduler with standby replicas.
 *
 * Exactly one instance holds the lease and dispatches; the others poll
 * {@see Lease::acquire()} and take over when the holder dies and its
 * lease expires. Failover is safe by construction: the new leader
 * resumes from the committed watermark, so a handover can duplicate a
 * tick's occurrences but never lose them.
 */
interface Lease
{
    /**
     * Non-blocking attempt to take the lease.
     */
    public function acquire(): bool;

    /**
     * Extend a held lease. Returning false means leadership is lost and
     * the caller must stop dispatching immediately.
     */
    public function renew(): bool;

    /**
     * Give the lease up. Safe to call when not held.
     */
    public function release(): void;
}
