<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * Persistence for the scheduler's watermark — where the last committed
 * window closed. Back it with shared storage (Redis, a database row) and
 * a replacement process resumes coverage where its predecessor stopped
 * instead of losing every occurrence that fell in the gap.
 */
interface State
{
    public function get(): ?string;

    public function put(string $value): void;
}
