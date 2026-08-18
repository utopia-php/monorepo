<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * One schedule as the source of truth describes it: a lightweight
 * descriptor the reconciler can diff cheaply. Building the actual
 * {@see Schedule} (parsing the spec, hydrating context) is deferred to
 * the `make` callable and happens only when `version` changes.
 */
final readonly class Row
{
    /**
     * @param string $id stable identity of the schedule in the source
     * @param string $version opaque change marker (an updated-at stamp works); a new
     *                        version re-makes the entry and re-arms a delivered one-shot
     * @param mixed $data raw source data, passed through to `make`
     * @param bool $active false marks a soft-deleted row: the entry is removed in both
     *                     full and incremental syncs
     * @param \DateTimeImmutable|null $activeFrom occurrences before this moment are never
     *                                            delivered — set it to the row's last change
     *                                            time so a schedule created or edited during
     *                                            downtime does not backfill under the old
     *                                            watermark with the old definition
     */
    public function __construct(
        public string $id,
        public string $version,
        public mixed $data = null,
        public bool $active = true,
        public ?\DateTimeImmutable $activeFrom = null,
    ) {}
}
