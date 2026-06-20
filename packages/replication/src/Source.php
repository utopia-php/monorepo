<?php

namespace Utopia\Replication;

/**
 * A change-data-capture source. Implementations stream row-level changes from a
 * database's replication log — MySQL (binlog) today, conceivably Postgres
 * (logical replication) or MongoDB (change streams) tomorrow.
 */
interface Source
{
    /**
     * Connect and begin streaming.
     *
     * @param string|null $position Opaque checkpoint to resume from (e.g. a GTID
     *                              set). Null starts from the source's current
     *                              position (only new changes).
     */
    public function start(?string $position = null): void;

    /**
     * Blocking stream of row changes. In a coroutine runtime this yields while
     * waiting on the source.
     *
     * @return \Generator<Change>
     */
    public function getChanges(): \Generator;

    /**
     * Stop streaming and release the connection.
     */
    public function stop(): void;
}
