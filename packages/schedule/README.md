# Utopia Schedule

An opinionated scheduler for PHP. One running loop with two duties: reconcile schedules into memory from a source of truth (usually a database), and dispatch their occurrences at the right time — cron, fixed interval, delayed, or at a fixed moment. Built so a run can be late, but never silently lost.

This library is part of the [Utopia PHP Framework](https://github.com/utopia-php).

## Why opinionated

Most schedulers ask "what is due *now*?" in a loop, and sync their schedule list with "what changed since last time?". Both questions harbor production-grade defects:

- **Evaluation races.** While the loop walks thousands of schedules, "now" keeps moving. When a tick starts milliseconds before a minute boundary, the boundary crosses mid-loop and every occurrence sitting on it falls between two answers — dropped, with no error and no trace.
- **Gaps have no owner.** Whatever falls between two ticks, or between a crash and a restart, was due in a moment nobody ever asks about again.
- **Deletes are invisible.** A change feed keyed on an updated-at column never sees a hard-deleted row, so removed schedules keep firing from memory until the next restart.

Utopia Schedule replaces the questions. Occurrences are selected from an explicit half-open window `[start, end)`; each window opens exactly where the previous committed window closed, and the boundary (the *watermark*) persists through a storage port as part of a leadership claim. Windows tile the timeline: every occurrence belongs to exactly one window, no matter how slowly the loop runs, how far the tick phase drifts, or how often the process restarts. And reconciliation is level-based: the source states the full desired set, the scheduler diffs — so removals converge by construction.

## Getting started

Install with Composer:

```bash
composer require utopia-php/schedule
```

Describe the source of truth and run:

```php
<?php

use Utopia\Schedule\Cron;
use Utopia\Schedule\Entry;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Row;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;

$scheduler = new Scheduler(
    source: new Source(
        // The full desired set, as cheap descriptors. Runs every 10 seconds.
        list: function (): iterable {
            foreach ($database->find('schedules') as $document) {
                yield new Row(
                    id: $document->getId(),
                    version: $document->getAttribute('updatedAt'),
                    data: $document,
                    activeFrom: new DateTimeImmutable($document->getAttribute('updatedAt')),
                );
            }
        },
        // The expensive part — parsing, hydrating context — runs only when
        // a row is new or its version changed.
        make: fn (Row $row): Entry => new Entry(
            schedule: new Cron($row->data->getAttribute('schedule')),
            payload: ['projectId' => $row->data->getAttribute('projectId')],
        ),
    ),
    state: $redisBackedState,
);

$scheduler->run(function (Occurrence $occurrence) use ($queue): void {
    // $occurrence->id      — the row's identity
    // $occurrence->due     — when the run was scheduled for
    // $occurrence->payload — whatever make() attached
    $queue->enqueue($occurrence->id, $occurrence->payload);
});
```

`run()` ticks on a wall-anchored cadence: it sleeps to the next multiple of the tick interval instead of sleeping a fixed span after variable work, so the tick phase never drifts. A handler exception propagates before the tick commits, which means a supervised restart re-delivers the tick instead of losing it. Reconciliation errors go the other way — through the `onError` callback, leaving the last good view dispatching, because stale schedules beat a stopped scheduler. Call `stop()` — from the handler or a signal handler — to return after the current tick completes.

## Schedule semantics

| Schedule | Fires | Notes |
|----------|-------|-------|
| `new Cron('*/15 * * * *')` | on cron matches | Minute resolution, zero dependencies: the portable five-field dialect (`*`, values, ranges, steps over `*` or ranges, lists, `JAN`/`FRI` names, `7` as Sunday, `@daily`-style macros, the vixie either-day rule). Invalid, never-matching, and Quartz-extension expressions throw at construction instead of becoming a silent no-op. |
| `new Interval(900)` | every 900 seconds | Occurrences sit on a deterministic grid (anchor + k × seconds, epoch-anchored by default), so the cadence survives restarts instead of re-phasing to process boot. Pass an anchor to set the phase. |
| `At::in(300)` | once, 300 seconds after the call | The moment is absolute from construction. |
| `new At($dateTime)` | once, at `$dateTime` | |

All types implement one contract: `occurrencesBetween($start, $end)` returns the occurrences inside `[start, end)`, ascending. An occurrence exactly at `$start` belongs to the window; one exactly at `$end` belongs to the next.

A delivered one-shot is dropped from memory and tombstoned by its `(id, version)`, so the next sync does not re-add the row before the source records completion — the handler (or the worker it feeds) owns marking the row done. The same row returning with a new version is a genuine reschedule and runs again.

## Reconciliation

Reconciliation is level-based: `list()` returns the full desired set and the scheduler converges memory to it — additions, updates, and removals, including hard deletes no change feed can see. Two properties keep it cheap and safe:

- `make()` runs only for new or version-changed rows; an unchanged row costs a string compare.
- A listing that throws mid-iteration discards the whole batch: a failed sync must never look like a mass removal. A row whose `make()` throws is skipped and reported; its previous entry stays.
- Discovery lag cannot lose runs: a new or changed entry is covered once from its own start — its `activeFrom`, or the previous sync time — even when the watermark has already passed it. A one-shot due sooner than the sync cadence runs late instead of never.

For large sets, add a change feed and the sync turns incremental between full snapshots:

```php
<?php

new Source(
    list: $listAll,                 // full snapshot, every $relist seconds (default 300)
    make: $make,
    changes: fn (DateTimeImmutable $since): iterable => $listChangedSince($since),
    every: 10,                      // incremental cadence
);
```

The change feed carries updates and soft deletes (`active: false`); hard deletes converge on the next full snapshot. Updates are idempotent under the version diff, so an overlapping feed is harmless.

`Row::$activeFrom` anchors each entry's coverage: a schedule created — or edited — while the scheduler was down backfills only from its change time, never under the old watermark with the old definition, and a schedule the sync discovers late is still covered from its change time forward. Set it to the row's last change time.

## Surviving restarts and failover

Leadership and the watermark share one lifecycle — the leader advances both on every commit — so they share one record: the `Claim` (`token`, `expiresAt`, `windowEnd`), stored behind the `State` interface (`load` and an atomic `swap`; back it with Redis or a database row). Every commit is one compare-and-swap that renews the lease and advances the watermark together. That single write buys three properties:

- **Election is inherent.** Point replicas at the same state and exactly one dispatches; the others idle and take over when the claim expires — or immediately when `stop()` releases it. No lock service, no extra port.
- **Failover resumes coverage.** A successor takes the watermark from the claim it inherits: occurrences missed in the handover are delivered on its first tick, oldest first, bounded by `lookback` (default 300 seconds).
- **Commits are fenced.** A deposed leader's late commit no longer matches the stored token: nothing is written, the watermark never rewinds, and the new leader re-covers the in-flight window. A handover can duplicate a tick's occurrences; it can never lose them.

Tune with the `lease` option (seconds a claim lives without a commit; must outlive at least two ticks) and `token` (the instance identity; defaults to a random one). Replica clocks must agree to within a fraction of the lease. To shard instead of (or as well as) failing over, partition rows by a stable hash of their id — filter in `list()` — and give each partition its own `State` record; selection is pure per-schedule math, so any id-to-partition mapping that is exactly one-to-one is correct.

## Delivery model

`run()` wraps a two-phase primitive you can drive yourself:

```php
<?php

$scheduler->reconcile();

foreach ($scheduler->tick() as $occurrence) {
    $handle($occurrence);
}

$scheduler->commit();
```

`tick()` confirms (or takes) leadership and selects the next window's occurrences without advancing the watermark; `commit()` advances it and renews the claim in one swap. Skip `commit()` when handling fails and the next `tick()` re-delivers — at-least-once, by construction. With the default `lookahead: 0`, occurrences are handed over after they fall due, late by at most one tick interval (default 1 second). Setting `lookahead` hands future occurrences over early — `$occurrence->due` says when they are meant to run — for callers that enqueue with precise delays, at the cost of losing occurrences committed but not yet run when a crash hits.

## Metrics

Pass a `utopia-php/telemetry` adapter and the scheduler records the four golden signals:

| Signal | Metric | Meaning |
|--------|--------|---------|
| Latency | `schedule.dispatch.delay` (histogram, s) | how late each occurrence was handed to the handler |
| Latency | `schedule.tick.duration`, `schedule.reconcile.duration` (histograms, s) | time spent selecting and syncing |
| Traffic | `schedule.dispatch.total` (counter), `schedule.entries` (gauge) | occurrences dispatched; schedules in memory |
| Errors | `schedule.error.total` (counter, `stage` attribute) | reconcile, make, and dispatch failures |
| Saturation | `schedule.lag` (gauge, s) | how far the window start trails "now" — steady at about one interval; growing means the loop is falling behind |

## Testing time

`Clock` isolates the scheduler from wall time, and the bundled `TestClock` makes timing defects reproducible fixtures — including the class of defect this library exists to prevent: the test suite replays a tick phase creeping across a minute boundary at 1.5ms per tick for an hour and asserts every occurrence is delivered exactly once.

## Tests

```bash
composer test
```

## Copyright and license

The MIT License (MIT). Please see the [license file](LICENSE) for more information.
