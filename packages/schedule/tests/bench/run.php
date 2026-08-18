<?php

declare(strict_types=1);

/**
 * Scheduler throughput at production scale: 10,000 mixed schedules.
 * Simulated time drives the scheduler through a TestClock while wall
 * time is measured, so a run is deterministic in behavior and honest
 * about cost. Informational only — correctness lives in the test suite.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Utopia\Schedule\Cron;
use Utopia\Schedule\Entry;
use Utopia\Schedule\Interval;
use Utopia\Schedule\MemoryState;
use Utopia\Schedule\Row;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\TestClock;

const SCHEDULES = 10_000;
const TICKS = 30;

$expressions = ['* * * * *', '*/5 * * * *', '*/15 * * * *', '0 * * * *', '30 9 * * MON-FRI', '0 0 1 * *'];
$intervals = [30, 60, 300, 900, 3600];

$rows = [];
for ($i = 0; $i < SCHEDULES; ++$i) {
    $rows[] = new Row("s{$i}", 'v1', $i);
}

$make = function (Row $row) use ($expressions, $intervals): Entry {
    $index = is_int($row->data) ? $row->data : 0;

    // 30% cron, 70% interval — roughly the shape of a real fleet.
    return $index % 10 < 3
        ? new Entry(new Cron($expressions[$index % count($expressions)]))
        : new Entry(new Interval($intervals[$index % count($intervals)]));
};

$clock = new TestClock(new DateTimeImmutable('2026-08-18 12:00:30.250000'));
$scheduler = new Scheduler(
    source: new Source(list: fn(): array => $rows, make: $make),
    state: new MemoryState(),
    clock: $clock,
    interval: 60,
    lease: 600,
);

$measure = function (string $label, callable $work, int $times = 1): void {
    $durations = [];
    for ($i = 0; $i < $times; ++$i) {
        $start = hrtime(true);
        $work($i);
        $durations[] = (hrtime(true) - $start) / 1e6;
    }
    sort($durations);
    $p50 = $durations[(int) floor(count($durations) * 0.50)];
    $max = $durations[count($durations) - 1];
    printf("%-46s p50 %8.2fms   max %8.2fms\n", $label, $p50, $max);
};

printf("schedules: %d (30%% cron / 70%% interval), tick interval: 60s, ticks: %d\n\n", SCHEDULES, TICKS);

$measure('reconcile: full snapshot, cold (all made)', fn() => $scheduler->reconcile());
$measure('reconcile: full snapshot, warm (version diff)', fn() => $scheduler->reconcile(full: true), 5);

$occurrences = 0;
$measure('tick + commit (one minute of coverage)', function () use ($scheduler, $clock, &$occurrences): void {
    $occurrences += count($scheduler->tick());
    $scheduler->commit();
    $clock->advance(60.0);
}, TICKS);

printf("\noccurrences dispatched: %d (%.1f per tick average)\n", $occurrences, $occurrences / TICKS);

$sparse = new Cron('0 12 29 2 *'); // leap day: the worst-case search
$start = new DateTimeImmutable('2026-08-18 12:00:30.250000');
$found = 0;
$measure('cron next-occurrence: leap-day expression x1000', function () use ($sparse, $start, &$found): void {
    for ($i = 0; $i < 1000; ++$i) {
        $found += count($sparse->occurrencesBetween($start, $start->modify('+60 seconds')));
    }
});
printf("leap-day matches inside a 60s window: %d (expected 0)\n", $found);
