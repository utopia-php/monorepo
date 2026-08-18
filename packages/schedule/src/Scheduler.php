<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;

/**
 * A running loop with two duties: reconcile schedules into memory from
 * a source of truth, and dispatch their occurrences at the right time.
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
 *   empty windows instead of duplicating already-delivered occurrences.
 * - Reconciliation is level-based: a full snapshot diff converges
 *   additions, updates and removals — including hard deletes no change
 *   feed can see. Incremental syncs are an optimization between full
 *   snapshots, never the correctness mechanism.
 * - With a {@see Lease}, replicas elect one dispatcher; failover resumes
 *   from the committed watermark, trading duplicates for losses never.
 *
 * One instance is one loop: drive it from a single coroutine or process.
 *
 * @phpstan-type Registered array{schedule: Schedule, payload: mixed, version: string, activeFrom: \DateTimeImmutable|null}
 */
final class Scheduler
{
    /** @var array<string, Registered> */
    private array $entries = [];

    /**
     * Delivered one-shots, id => version. Kept so the next snapshot does
     * not re-add a row whose completion the source has not recorded yet;
     * evicted when the row leaves a full snapshot, re-armed when the row
     * returns with a new version.
     *
     * @var array<string, string>
     */
    private array $tombstones = [];

    private ?\DateTimeImmutable $pendingWindowEnd = null;

    /** @var list<string> */
    private array $pendingOneShots = [];

    private bool $running = false;

    private bool $held = false;

    /** Cursor for incremental syncs; null forces the next sync to be full. */
    private ?\DateTimeImmutable $lastSyncAt = null;

    private float $nextSyncAt = 0.0;

    private float $nextRelistAt = 0.0;

    private readonly Histogram $dispatchDelay;

    private readonly Histogram $tickDuration;

    private readonly Histogram $reconcileDuration;

    private readonly Counter $dispatchTotal;

    private readonly Counter $errorTotal;

    private readonly Gauge $entriesGauge;

    private readonly Gauge $lagGauge;

    /**
     * @param Source $source the source of truth the loop reconciles against
     * @param State $state watermark persistence; share it (Redis, database) to survive restarts
     * @param Clock $clock time source; swap for {@see TestClock} in tests
     * @param int $interval seconds between ticks in {@see Scheduler::run()}, wall-anchored
     * @param int $lookahead seconds a tick may reach past "now". Zero (the default) delivers
     *                       occurrences after they are due, late by at most one interval, and
     *                       keeps at-least-once intact. Raising it trades that guarantee for
     *                       early hand-off: callers receive future occurrences (`$occurrence->due`)
     *                       to schedule precisely, but a crash after commit loses them.
     * @param int $lookback ceiling, in seconds, on how far behind the watermark may start a window
     * @param Lease|null $lease leader election for standby replicas; without one this instance
     *                          always dispatches
     * @param Telemetry $telemetry metric backend; the four golden signals are recorded as
     *                             schedule.dispatch.delay and schedule.tick.duration (latency),
     *                             schedule.dispatch.total and schedule.entries (traffic),
     *                             schedule.error.total by stage (errors) and schedule.lag
     *                             (saturation: seconds the window start trails "now")
     * @param \Closure|null $onError receives reconciliation failures (a sync that throws, a row
     *                               `make` rejects) so dispatch keeps running on the last good
     *                               view; without it those failures rethrow
     *
     * @throws \InvalidArgumentException on non-positive $interval or negative $lookahead/$lookback
     */
    public function __construct(
        private readonly Source $source,
        private readonly State $state = new MemoryState(),
        private readonly Clock $clock = new SystemClock(),
        private readonly int $interval = 1,
        private readonly int $lookahead = 0,
        private readonly int $lookback = 300,
        private readonly ?Lease $lease = null,
        Telemetry $telemetry = new NoTelemetry(),
        private readonly ?\Closure $onError = null,
    ) {
        if ($interval < 1) {
            throw new \InvalidArgumentException('Tick interval must be at least 1 second');
        }
        if ($lookahead < 0 || $lookback < 0) {
            throw new \InvalidArgumentException('Lookahead and lookback must not be negative');
        }

        // Dispatch delay spans "on time" (well under a second) to a full
        // lookback of catch-up; the default OpenTelemetry buckets stop at
        // 10 seconds and would flatten every recovery into one bucket.
        $this->dispatchDelay = $telemetry->createHistogram(
            'schedule.dispatch.delay',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.1, 0.5, 1, 2, 5, 15, 30, 60, 120, 300]],
        );
        $this->tickDuration = $telemetry->createHistogram('schedule.tick.duration', 's');
        $this->reconcileDuration = $telemetry->createHistogram('schedule.reconcile.duration', 's');
        $this->dispatchTotal = $telemetry->createCounter('schedule.dispatch.total');
        $this->errorTotal = $telemetry->createCounter('schedule.error.total');
        $this->entriesGauge = $telemetry->createGauge('schedule.entries');
        $this->lagGauge = $telemetry->createGauge('schedule.lag', 's');
    }

    /**
     * Synchronize memory with the source: one full snapshot diff, or one
     * incremental pass when a change feed is configured and a cursor
     * exists. {@see Scheduler::run()} calls this on the source's cadence;
     * call it directly when driving the loop yourself.
     *
     * A source that throws mid-listing discards the whole batch — a
     * failed sync must never look like a mass removal. A row whose
     * `make` throws is skipped (reported through onError) and its
     * previous entry, if any, stays.
     */
    public function reconcile(bool $full = false): void
    {
        $started = microtime(true);
        $syncStart = $this->clock->now();

        $changes = $this->source->changes;
        $since = $this->lastSyncAt;

        if (!$full && $changes instanceof \Closure && $since instanceof \DateTimeImmutable) {
            $feed = $changes($since);
        } else {
            $full = true;
            $feed = ($this->source->list)();
        }

        $rows = [];
        foreach ($feed as $row) {
            $rows[$row->id] = $row;
        }

        foreach ($rows as $id => $row) {
            if (!$row->active) {
                unset($this->entries[$id], $this->tombstones[$id]);
                continue;
            }

            if (($this->tombstones[$id] ?? null) === $row->version) {
                continue; // delivered one-shot whose row the source still lists
            }
            unset($this->tombstones[$id]);

            $existing = $this->entries[$id] ?? null;
            if ($existing !== null && $existing['version'] === $row->version) {
                continue;
            }

            try {
                $entry = ($this->source->make)($row);
            } catch (\Throwable $error) {
                $this->report($error, 'make');
                continue;
            }

            $this->entries[$id] = [
                'schedule' => $entry->schedule,
                'payload' => $entry->payload,
                'version' => $row->version,
                'activeFrom' => $row->activeFrom,
            ];
        }

        if ($full) {
            foreach (array_keys($this->entries) as $id) {
                if (!isset($rows[$id])) {
                    unset($this->entries[$id]);
                }
            }
            foreach (array_keys($this->tombstones) as $id) {
                if (!isset($rows[$id])) {
                    unset($this->tombstones[$id]);
                }
            }
        }

        $this->lastSyncAt = $syncStart;
        $this->reconcileDuration->record(microtime(true) - $started, ['full' => $full]);
    }

    /**
     * Select every occurrence in the next window, oldest first.
     *
     * The window opens at the committed watermark (clamped to $lookback,
     * initialized to "now" on first ever run) and closes at now plus
     * $lookahead; an entry's activeFrom clamps its own start further.
     * The result is remembered as pending until {@see Scheduler::commit()};
     * ticking again without committing re-selects the same occurrences.
     *
     * @return list<Occurrence>
     */
    public function tick(): array
    {
        $started = microtime(true);
        $now = $this->clock->now();
        $end = $this->lookahead > 0 ? $now->modify("{$this->lookahead} seconds") : $now;
        $start = $this->watermark() ?? $now;

        $floor = $now->modify("-{$this->lookback} seconds");
        if ($start < $floor) {
            $start = $floor;
        }

        $this->entriesGauge->record(\count($this->entries));
        $this->lagGauge->record(max(0.0, (float) $now->format('U.u') - (float) $start->format('U.u')));

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

        foreach ($this->entries as $id => $entry) {
            $entryStart = $entry['activeFrom'] !== null && $entry['activeFrom'] > $start ? $entry['activeFrom'] : $start;
            if ($entryStart >= $end) {
                continue;
            }

            $dues = $entry['schedule']->occurrencesBetween($entryStart, $end);

            foreach ($dues as $due) {
                $occurrences[] = new Occurrence($id, $due, $entry['payload']);
            }

            if ($dues !== [] && !$entry['schedule']->recurring()) {
                $oneShots[] = $id;
            }
        }

        usort($occurrences, fn(Occurrence $a, Occurrence $b): int => $a->due <=> $b->due ?: $a->id <=> $b->id);

        $this->pendingWindowEnd = $end;
        $this->pendingOneShots = $oneShots;
        $this->tickDuration->record(microtime(true) - $started);

        return $occurrences;
    }

    /**
     * Advance the watermark past the last tick's window and retire
     * delivered one-shots. Call after handling the tick's occurrences;
     * skipping it on failure is what makes re-delivery work.
     *
     * A delivered one-shot leaves a tombstone so the next snapshot does
     * not re-add its row before the source records completion.
     */
    public function commit(): void
    {
        if (!$this->pendingWindowEnd instanceof \DateTimeImmutable) {
            return;
        }

        $this->state->put($this->pendingWindowEnd->format('U.u'));

        foreach ($this->pendingOneShots as $id) {
            $entry = $this->entries[$id] ?? null;
            if ($entry === null) {
                continue;
            }

            $this->tombstones[$id] = $entry['version'];
            unset($this->entries[$id]);
        }

        $this->pendingWindowEnd = null;
        $this->pendingOneShots = [];
    }

    /**
     * The loop: elect (when a lease is configured), reconcile on the
     * source's cadence, then tick, dispatch and commit on a wall-anchored
     * cadence.
     *
     * Anchoring ticks to the clock instead of sleeping a fixed span
     * after variable work keeps the tick phase from drifting. A handler
     * exception propagates before commit, so a supervised restart
     * re-delivers the tick instead of losing it. Reconciliation errors
     * go through onError and leave the last good view dispatching —
     * stale schedules beat a stopped scheduler.
     *
     * @param callable(Occurrence): void $handler
     */
    public function run(callable $handler): void
    {
        $this->running = true;

        try {
            while ($this->running) {
                if (!$this->leading()) {
                    $this->clock->sleep((float) $this->interval);
                    continue;
                }

                $now = (float) $this->clock->now()->format('U.u');
                if ($now >= $this->nextSyncAt) {
                    $full = $this->source->relist > 0 && $now >= $this->nextRelistAt;

                    try {
                        $this->reconcile($full);
                    } catch (\Throwable $error) {
                        $this->report($error, 'reconcile');
                    }

                    $this->nextSyncAt = $now + $this->source->every;
                    if ($full) {
                        $this->nextRelistAt = $now + $this->source->relist;
                    }
                }

                foreach ($this->tick() as $occurrence) {
                    try {
                        $handler($occurrence);
                    } catch (\Throwable $error) {
                        $this->errorTotal->add(1, ['stage' => 'dispatch']);

                        throw $error;
                    }

                    $this->dispatchTotal->add(1);
                    $this->dispatchDelay->record(max(
                        0.0,
                        (float) $this->clock->now()->format('U.u') - (float) $occurrence->due->format('U.u'),
                    ));
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
        } finally {
            if ($this->held) {
                $this->lease?->release();
                $this->held = false;
            }
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

    private function leading(): bool
    {
        if (!$this->lease instanceof \Utopia\Schedule\Lease) {
            return true;
        }

        if ($this->held) {
            if ($this->lease->renew()) {
                return true;
            }

            $this->held = false;

            return false;
        }

        if (!$this->lease->acquire()) {
            return false;
        }

        $this->held = true;

        // A new leader must not trust memory from before the handover:
        // force a full reconcile and drop any uncommitted tick.
        $this->lastSyncAt = null;
        $this->nextSyncAt = 0.0;
        $this->pendingWindowEnd = null;
        $this->pendingOneShots = [];

        return true;
    }

    private function report(\Throwable $error, string $stage): void
    {
        $this->errorTotal->add(1, ['stage' => $stage]);

        if (!$this->onError instanceof \Closure) {
            throw $error;
        }

        ($this->onError)($error);
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
