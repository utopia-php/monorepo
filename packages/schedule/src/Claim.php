<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * The scheduler's shared record: who leads, until when, where committed
 * coverage stops, and how much of the source had been read when it
 * stopped there.
 *
 * Leadership and coverage share one lifecycle — the leader advances both
 * on every commit — so they share one record, and one compare-and-swap
 * makes the commit fenced: a deposed leader's late write no longer
 * matches the stored token and cannot rewind coverage.
 *
 * `syncedUntil` is what lets a successor tell the difference between a
 * schedule its predecessor had already covered and one the predecessor
 * never saw. Without it a cold start has to choose between replaying
 * history for every schedule it loads and losing the runs of schedules
 * created in the predecessor's last unsynced moments.
 */
final readonly class Claim
{
    /**
     * @param string $token the holder's identity; empty when the claim was released
     * @param float $expiresAt Unix seconds; at or past this moment any instance may take over
     * @param string|null $windowEnd where committed coverage stops, as a `U.u` timestamp;
     *                               null before the first ever commit
     * @param string|null $syncedUntil when the source was last read successfully, as a `U.u`
     *                                 timestamp. Every schedule that existed then was known,
     *                                 so the coverage above accounts for it; anything created
     *                                 later was invisible and is owed its own coverage
     */
    public function __construct(
        public string $token,
        public float $expiresAt,
        public ?string $windowEnd,
        public ?string $syncedUntil = null,
    ) {}
}
