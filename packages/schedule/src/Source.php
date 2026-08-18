<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * The source of truth for a scheduler's entries, usually a database.
 *
 * `list` returns the full desired set as {@see Row} descriptors; the
 * scheduler diffs it against memory, so additions, updates and removals
 * — including hard deletes — all converge. `make` builds the
 * {@see Entry} for a row and runs only when the row is new or its
 * version changed, keeping frequent syncs cheap.
 *
 * `changes`, when given, makes syncs incremental: it receives the time
 * of the last successful sync and returns rows changed since, including
 * soft-deleted ones as `active: false`. Hard deletes are invisible to a
 * change feed, so a full `list` sync still runs every `relist` seconds
 * to converge them. Row updates are idempotent under the version diff,
 * so an overlapping change feed is harmless.
 */
final readonly class Source
{
    /** @var \Closure(): iterable<Row> */
    public \Closure $list;

    /** @var \Closure(Row): Entry */
    public \Closure $make;

    /** @var (\Closure(\DateTimeImmutable): iterable<Row>)|null */
    public ?\Closure $changes;

    /**
     * @param callable(): iterable<Row> $list
     * @param callable(Row): Entry $make
     * @param (callable(\DateTimeImmutable): iterable<Row>)|null $changes
     * @param int $every seconds between syncs
     * @param int $relist seconds between full snapshot syncs when $changes is given;
     *                    0 disables periodic relisting (removals then converge only
     *                    through soft deletes or a manual `reconcile(full: true)`)
     *
     * @throws \InvalidArgumentException on a non-positive $every or negative $relist
     */
    public function __construct(
        callable $list,
        callable $make,
        ?callable $changes = null,
        public int $every = 10,
        public int $relist = 300,
    ) {
        if ($every < 1) {
            throw new \InvalidArgumentException('Sync cadence must be at least 1 second');
        }
        if ($relist < 0) {
            throw new \InvalidArgumentException('Relist cadence must not be negative');
        }

        $this->list = $list(...);
        $this->make = $make(...);
        $this->changes = $changes === null ? null : $changes(...);
    }
}
