<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * What one iteration of {@see Scheduler::run()} did, handed to the
 * `onTick` observer once the iteration is over.
 *
 * The metrics the scheduler records answer "how is it behaving"; this
 * answers "what just happened" — which window was covered, how many runs
 * it carried, where the time went, and whether the coverage stuck. It is
 * the hook for a span or a log line per tick:
 *
 * ```php
 * onTick: function (Tick $tick): void {
 *     Span::init('schedule.tick')
 *         ->set('span.started_at', (float) $tick->startedAt->format('U.u'))
 *         ->set('schedule.window.start', $tick->windowStart?->format('c'))
 *         ->set('schedule.window.end', $tick->windowEnd?->format('c'))
 *         ->set('schedule.occurrences', $tick->count)
 *         ->set('schedule.leader', $tick->leader)
 *         ->set('schedule.committed', $tick->committed)
 *         ->finish();
 * }
 * ```
 */
final readonly class Tick
{
    /**
     * @param \DateTimeImmutable $startedAt when the iteration began
     * @param bool $leader whether this instance held the claim; a follower reports nothing
     *                     else, having selected no window
     * @param \DateTimeImmutable|null $windowStart inclusive start of the window covered
     * @param \DateTimeImmutable|null $windowEnd exclusive end of the window covered
     * @param int $count occurrences handed to the handler
     * @param float $selectDuration seconds spent reconciling and selecting — the
     *                              scheduler's own cost, which grows with the fleet
     * @param float $dispatchDuration seconds the handler held the loop — the caller's cost,
     *                                which must stay well inside the tick interval and the lease
     * @param bool $committed whether the watermark advanced. False means the window is
     *                        re-covered next tick: the handler threw, or leadership was lost
     *                        mid-tick and the commit was fenced
     */
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public bool $leader,
        public ?\DateTimeImmutable $windowStart,
        public ?\DateTimeImmutable $windowEnd,
        public int $count,
        public float $selectDuration,
        public float $dispatchDuration,
        public bool $committed,
    ) {}
}
