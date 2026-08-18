<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * A single due run of a registered schedule.
 */
final readonly class Occurrence
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $due,
        public mixed $payload = null,
    ) {}
}
