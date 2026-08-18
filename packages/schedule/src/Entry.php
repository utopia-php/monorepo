<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * What `make` returns for a {@see Row}: the schedule to run and an
 * opaque payload handed to the dispatch handler with every occurrence.
 */
final readonly class Entry
{
    public function __construct(
        public Schedule $schedule,
        public mixed $payload = null,
    ) {}
}
