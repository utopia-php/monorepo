<?php

namespace Utopia\Replication;

/**
 * A single row-change decoded from the binlog.
 *
 * For UPDATE events, {@see $rows} holds the after-image of each updated row.
 */
final class Change
{
    public const string INSERT = 'insert';
    public const string UPDATE = 'update';
    public const string DELETE = 'delete';

    /**
     * @param string $action One of INSERT|UPDATE|DELETE.
     * @param string $database Source schema name.
     * @param string $table Physical table name (e.g. "console15x_projects").
     * @param array<int, array<string, mixed>> $rows Affected rows as column => value maps.
     * @param string $gtid Executed-GTID-set of all transactions committed *before* this event's
     *                     transaction — a resumable checkpoint token. Resuming from this value
     *                     re-streams the transaction that produced this change (safe for
     *                     idempotent consumers).
     */
    public function __construct(
        public readonly string $action,
        public readonly string $database,
        public readonly string $table,
        public readonly array $rows,
        public readonly string $gtid,
    ) {}
}
