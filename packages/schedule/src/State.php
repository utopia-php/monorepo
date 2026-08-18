<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Utopia\Schedule\State\Claim;

/**
 * Storage for the scheduler's {@see Claim}. Back it with shared storage
 * (Redis, a database row) and replicas elect one dispatcher, a
 * replacement resumes coverage where its predecessor stopped, and a
 * deposed leader's late commit is rejected instead of rewinding the
 * watermark.
 *
 * Implementations must make {@see State::swap()} atomic: on Redis a Lua
 * script or WATCH/MULTI, on a database `UPDATE … WHERE token = ?`.
 */
interface State
{
    public function load(): ?Claim;

    /**
     * Store $next only if the current record's token equals $expected
     * (null means no record exists yet).
     *
     * @return bool true when the swap happened
     */
    public function swap(?string $expected, Claim $next): bool;
}
