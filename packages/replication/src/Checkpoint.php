<?php

namespace Utopia\Replication;

/**
 * Durable store for a {@see Source}'s resume position (e.g. a GTID set).
 *
 * Keep the store OUTSIDE the data the source is tailing: a checkpoint written
 * into the replicated database would itself be streamed back as a change and
 * trigger an endless write-then-observe loop. A file on a mounted volume, or a
 * datastore the source does not replicate, are good homes.
 */
interface Checkpoint
{
    /**
     * The last persisted position, or null if none has been stored yet (a fresh
     * consumer should then start from the source's current position).
     */
    public function get(): ?string;

    /**
     * Persist the position reached so a restart can resume from it. Called on the
     * streaming hot path, so implementations should be cheap (and may coalesce
     * writes — resuming is idempotent).
     */
    public function set(string $position): void;
}
