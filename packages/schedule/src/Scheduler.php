<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * The opinionated way to run schedules.
 *
 * Correctness rules the scheduler enforces, distilled from production
 * failures of "check what is due now" loops:
 *
 * - Selection happens over an explicit half-open window, never against a
 *   moving "now". A minute boundary the loop crosses mid-evaluation
 *   cannot drop the occurrence sitting on it.
 * - Windows tile: each opens where the previous committed window closed
 *   (the watermark, held in {@see State}). Nothing falls between ticks,
 *   and with shared state nothing falls across a restart either.
 * - Delivery is at-least-once: {@see Scheduler::commit()} advances the
 *   watermark only after the caller has handled a tick's occurrences.
 *   A crash mid-tick re-delivers; it never silently skips.
 * - Catch-up is bounded: a watermark older than $lookback seconds is
 *   clamped, so recovery after a long outage replays a capped burst
 *   instead of everything since the outage began.
 * - The watermark never rewinds: a clock stepping backwards produces
 *   empty windows until real time passes the committed edge, instead of
 *   duplicating already-delivered occurrences.
 */
final class Scheduler
{
    /** @var array<string, Schedule> */
    private array $schedules = [];

    private ?\DateTimeImmutable $pendingWindowEnd = null;

    /** @var list<string> */
    private array $pendingOneShots = [];

    private bool $running = false;

    /**
     * @param State $state watermark persistence; share it (Redis, database) to survive restarts
     * @param Clock $clock time source; swap for {@see TestClock} in tests
     * @param int $interval seconds between ticks in {@see Scheduler::run()}, wall-anchored
     * @param int $lookahead seconds a tick may reach past "now". Zero (the default) delivers
     *                       occurrences after they are due, late by at most one interval, and
     *                       keeps at-least-once intact. Raising it trades that guarantee for
     *                       early hand-off: callers receive future occurrences (`$occurrence->due`)
     *                       to schedule precisely, but a crash after commit loses them.
     * @param int $lookback ceiling, in seconds, on how far behind the watermark may start a window
     *
     * @throws \InvalidArgumentException on non-positive $interval or negative $lookahead/$lookback
     */
    public function __construct(
        private readonly State $state = new MemoryState(),
        private readonly Clock $clock = new SystemClock(),
        private readonly int $interval = 1,
        private readonly int $lookahead = 0,
        private readonly int $lookback = 300,
    ) {
        if ($interval < 1) {
            throw new \InvalidArgumentException('Tick interval must be at least 1 second');
        }
        if ($lookahead < 0 || $lookback < 0) {
            throw new \InvalidArgumentException('Lookahead and lookback must not be negative');
        }
    }

    public function set(string $id, Schedule $schedule): void
    {
        $this->schedules[$id] = $schedule;
    }

    public function remove(string $id): void
    {
        unset($this->schedules[$id]);
    }

    /**
     * Select every occurrence in the next window, oldest first.
     *
     * The window opens at the committed watermark (clamped to $lookback,
     * initialized to "now" on first ever run) and closes at now plus
     * $lookahead. The result is remembered as pending until
     * {@see Scheduler::commit()}; ticking again without committing
     * re-selects the same occurrences.
     *
     * @return list<Occurrence>
     */
    public function tick(): array
    {
        $now = $this->clock->now();
        $end = $this->lookahead > 0 ? $now->modify("{$this->lookahead} seconds") : $now;
        $start = $this->watermark() ?? $now;

        $floor = $now->modify("-{$this->lookback} seconds");
        if ($start < $floor) {
            $start = $floor;
        }

        if ($start >= $end) {
            // Nothing to cover, but committing $start still initializes the
            // watermark on first run — and never rewinds it when the clock
            // has stepped backwards past the committed edge.
            $this->pendingWindowEnd = $start;
            $this->pendingOneShots = [];

            return [];
        }

        $occurrences = [];
        $oneShots = [];

        foreach ($this->schedules as $id => $schedule) {
            $dues = $schedule->occurrencesBetween($start, $end);

            foreach ($dues as $due) {
                $occurrences[] = new Occurrence($id, $due);
            }

            if ($dues !== [] && !$schedule->recurring()) {
                $oneShots[] = $id;
            }
        }

        usort($occurrences, fn(Occurrence $a, Occurrence $b): int => $a->due <=> $b->due ?: $a->id <=> $b->id);

        $this->pendingWindowEnd = $end;
        $this->pendingOneShots = $oneShots;

        return $occurrences;
    }

    /**
     * Advance the watermark past the last tick's window and drop
     * delivered one-shot schedules. Call after handling the tick's
     * occurrences; skipping it on failure is what makes re-delivery work.
     */
    public function commit(): void
    {
        if (!$this->pendingWindowEnd instanceof \DateTimeImmutable) {
            return;
        }

        $this->state->put($this->pendingWindowEnd->format('U.u'));

        foreach ($this->pendingOneShots as $id) {
            unset($this->schedules[$id]);
        }

        $this->pendingWindowEnd = null;
        $this->pendingOneShots = [];
    }

    /**
     * Tick forever on a wall-anchored cadence: deliver, commit, sleep to
     * the next multiple of $interval. Anchoring the cadence to the clock
     * instead of sleeping a fixed span after variable work keeps the tick
     * phase from drifting.
     *
     * A handler exception propagates before commit, so a supervisor
     * restart re-delivers the tick instead of losing it.
     *
     * @param callable(Occurrence): void $handler
     */
    public function run(callable $handler): void
    {
        $this->running = true;

        while ($this->running) {
            foreach ($this->tick() as $occurrence) {
                $handler($occurrence);
            }

            $this->commit();

            if (!$this->running) {
                break;
            }

            $phase = fmod((float) $this->clock->now()->format('U.u'), (float) $this->interval);
            $pause = (float) $this->interval - $phase;
            if ($pause < 0.001) {
                $pause += $this->interval;
            }

            $this->clock->sleep($pause);
        }
    }

    /**
     * Make {@see Scheduler::run()} return after the current tick
     * finishes delivering and committing.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    private function watermark(): ?\DateTimeImmutable
    {
        $stored = $this->state->get();
        if ($stored === null) {
            return null;
        }

        $watermark = \DateTimeImmutable::createFromFormat('U.u', $stored);

        return $watermark === false ? null : $watermark;
    }
}
