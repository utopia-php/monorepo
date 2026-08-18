<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * Deterministic clock for tests: time stands still until advanced, and
 * sleeping advances it instead of blocking. Sub-second phases — a tick
 * starting 59 milliseconds before a minute boundary, a sleep(60) that
 * resumes 1.5 milliseconds late — become exact, repeatable fixtures.
 */
final class TestClock implements Clock
{
    private float $timestamp;

    public function __construct(\DateTimeImmutable $start)
    {
        $this->timestamp = (float) $start->format('U.u');
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $this->timestamp));
        if ($now === false) {
            throw new \RuntimeException('TestClock holds an unrepresentable timestamp');
        }

        return $now;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->advance($seconds);
    }

    public function advance(float $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
