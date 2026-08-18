# Utopia Schedule

An opinionated scheduler for PHP. It runs cron expressions, fixed intervals, delayed jobs and fixed-time jobs — and it is built so a run can be late, but never silently lost.

This library is part of the [Utopia PHP Framework](https://github.com/utopia-php).

## Why opinionated

Most schedulers ask "what is due *now*?" in a loop. That question harbors two production-grade defects:

- **Evaluation races.** While the loop walks thousands of schedules, "now" keeps moving. When a tick starts milliseconds before a minute boundary, the boundary crosses mid-loop and every occurrence sitting on it falls between two answers — dropped, with no error and no trace.
- **Gaps have no owner.** Whatever falls between two ticks, or between a crash and a restart, was due in a moment nobody ever asks about again.

Utopia Schedule replaces the question. Every tick selects occurrences from an explicit half-open window `[start, end)`, each window opens exactly where the previous committed window closed, and the boundary (the *watermark*) persists through a storage port. Windows tile the timeline: every occurrence belongs to exactly one window, no matter how slowly the loop runs, how far the tick phase drifts, or how often the process restarts.

## Getting started

Install with Composer:

```bash
composer require utopia-php/schedule
```

Register schedules and run:

```php
<?php

use Utopia\Schedule\At;
use Utopia\Schedule\Cron;
use Utopia\Schedule\Interval;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;

$scheduler = new Scheduler();

$scheduler->set('report', new Cron('*/15 * * * *'));   // cron semantics
$scheduler->set('heartbeat', new Interval(30));        // every 30 seconds
$scheduler->set('cleanup', At::in(300));               // in 5 minutes, once
$scheduler->set('launch', new At(new DateTimeImmutable('2027-01-01 00:00:00'))); // at a fixed time, once

$scheduler->run(function (Occurrence $occurrence) {
    // $occurrence->id  — the key registered above
    // $occurrence->due — when the run was scheduled for
});
```

`run()` ticks on a wall-anchored cadence: it sleeps to the next multiple of the tick interval instead of sleeping a fixed span after variable work, so the tick phase never drifts. A handler exception propagates before the tick commits, which means a supervised restart re-delivers the tick instead of losing it. Call `stop()` — from the handler or a signal handler — to return after the current tick completes.

## Schedule semantics

| Schedule | Fires | Notes |
|----------|-------|-------|
| `new Cron('*/15 * * * *')` | on cron matches | Minute resolution. Invalid and never-matching expressions throw at construction instead of becoming a silent no-op. |
| `new Interval(900)` | every 900 seconds | Occurrences sit on a deterministic grid (anchor + k × seconds, epoch-anchored by default), so the cadence survives restarts instead of re-phasing to process boot. Pass an anchor to set the phase. |
| `At::in(300)` | once, 300 seconds after the call | The moment is absolute from construction; the scheduler drops the entry after delivery. |
| `new At($dateTime)` | once, at `$dateTime` | Dropped after delivery. |

All three types implement one contract: `occurrencesBetween($start, $end)` returns the occurrences inside `[start, end)`, ascending. An occurrence exactly at `$start` belongs to the window; one exactly at `$end` belongs to the next.

## Surviving restarts

The watermark lives behind the `State` interface. The default `MemoryState` covers a single process; back it with shared storage and a replacement process resumes coverage where its predecessor stopped:

```php
<?php

use Utopia\Schedule\Scheduler;
use Utopia\Schedule\State;

$state = new class implements State {
    public function __construct(private \Redis $redis = new \Redis()) {}

    public function get(): ?string
    {
        $value = $this->redis->get('scheduler-watermark');
        return \is_string($value) ? $value : null;
    }

    public function put(string $value): void
    {
        $this->redis->set('scheduler-watermark', $value);
    }
};

$scheduler = new Scheduler(state: $state);
```

Occurrences missed while the process was down are delivered on the first tick after it returns, oldest first. The `lookback` option (default 300 seconds) caps how far back a stale watermark reaches, so recovery after a long outage replays a bounded burst rather than everything since the outage began.

Run one scheduler per watermark. Two instances sharing state race benignly on the watermark but deliver duplicates; use a lock or single replica for exactly-one-runner deployments.

## Delivery model

`run()` wraps a two-phase primitive you can drive yourself:

```php
<?php

foreach ($scheduler->tick() as $occurrence) {
    $handle($occurrence);
}

$scheduler->commit();
```

`tick()` selects the next window's occurrences without advancing the watermark; `commit()` advances it. Skip `commit()` when handling fails and the next `tick()` re-delivers — at-least-once, by construction. With the default `lookahead: 0`, occurrences are handed over after they fall due, late by at most one tick interval (default 1 second). Setting `lookahead` hands future occurrences over early — `$occurrence->due` says when they are meant to run — for callers that enqueue with precise delays, at the cost of losing occurrences committed but not yet run when a crash hits.

## Testing time

`Clock` isolates the scheduler from wall time, and the bundled `TestClock` makes timing defects reproducible fixtures — including the class of defect this library exists to prevent:

```php
<?php

use Utopia\Schedule\Cron;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\TestClock;

// A tick phase 227ms before a minute boundary, drifting 1.5ms per tick
// across it: the shape that made a "now"-based scheduler drop 90% of
// runs. Tiled windows deliver every occurrence exactly once.
$clock = new TestClock(new DateTimeImmutable('2026-08-17 15:53:59.773'));
$scheduler = new Scheduler(clock: $clock, interval: 60);
$scheduler->set('fn', new Cron('*/5 * * * *'));

for ($tick = 0; $tick < 60; $tick++) {
    $occurrences = $scheduler->tick();
    $scheduler->commit();
    $clock->advance(60.0015);
}
```

## Tests

```bash
composer test
```

## Copyright and license

The MIT License (MIT). Please see the [license file](LICENSE) for more information.
