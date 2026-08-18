<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\At;
use Utopia\Schedule\Cron;
use Utopia\Schedule\Entry;
use Utopia\Schedule\Interval;
use Utopia\Schedule\MemoryState;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Row;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\TestClock;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class ReconcileTest extends TestCase
{
    public function testFullSnapshotDiffAddsUpdatesAndRemoves(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $set = new RowSet([
            new Row('a', 'v1', '* * * * *'),
            new Row('b', 'v1', 'interval'),
        ]);
        $made = new class {
            public int $count = 0;
        };
        $scheduler = new Scheduler(
            source: new Source(
                list: $set->list(...),
                make: function (Row $row) use ($made): Entry {
                    ++$made->count;
                    $spec = $row->data;
                    if (!\is_string($spec)) {
                        throw new \InvalidArgumentException('spec must be a string');
                    }

                    return $spec === 'interval'
                        ? new Entry(new Interval(60), $row->id)
                        : new Entry(new Cron($spec), $row->id);
                },
            ),
            state: new MemoryState(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $this->assertSame(2, $made->count);

        $scheduler->reconcile();
        $this->assertSame(2, $made->count, 'unchanged versions must not be re-made');

        $set->rows = [
            new Row('a', 'v2', '*/2 * * * *'), // updated
            // 'b' hard-deleted
        ];
        $scheduler->reconcile();
        $this->assertSame(3, $made->count, 'only the changed row is re-made');

        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(120.0);

        $this->assertSame(
            [['a', '03:02:00', 'a']],
            array_map(
                fn(Occurrence $occurrence): array => [$occurrence->id, $occurrence->due->format('H:i:s'), $occurrence->payload],
                $scheduler->tick(),
            ),
            'removed rows stop firing; payload rides along',
        );
    }

    public function testIncrementalSyncConvergesRemovalsOnlyThroughRelistOrSoftDelete(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $full = new RowSet([new Row('a', 'v1'), new Row('b', 'v1')]);
        $changed = new RowSet();
        $scheduler = new Scheduler(
            source: new Source(
                list: $full->list(...),
                make: fn(Row $row): Entry => new Entry(new Interval(60), $row->id),
                changes: fn(\DateTimeImmutable $since): array => $changed->list(),
            ),
            state: new MemoryState(),
            clock: $clock,
        );

        $scheduler->reconcile(); // first sync is always full
        $scheduler->tick();
        $scheduler->commit();

        // 'b' is hard-deleted at the source; the change feed cannot see it.
        $full->rows = [new Row('a', 'v1')];
        $scheduler->reconcile();
        $clock->advance(60.0);

        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());
        $this->assertSame(['a', 'b'], $ids, 'a change feed cannot converge a hard delete');
        $scheduler->commit();

        $scheduler->reconcile(full: true);
        $clock->advance(60.0);
        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());
        $this->assertSame(['a'], $ids, 'the relist converges the hard delete');
        $scheduler->commit();

        // Soft deletes do flow through the change feed.
        $changed->rows = [new Row('a', 'v2', active: false)];
        $scheduler->reconcile();
        $clock->advance(60.0);

        $this->assertSame([], $scheduler->tick());
    }

    public function testDeliveredOneShotIsTombstonedUntilTheSourceForgetsIt(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));

        $set = new RowSet([new Row('job', 'v1', '2026-08-18 03:00:30')]);
        $scheduler = new Scheduler(
            source: new Source(
                list: $set->list(...),
                make: function (Row $row): Entry {
                    $at = $row->data;
                    if (!\is_string($at)) {
                        throw new \InvalidArgumentException('time must be a string');
                    }

                    return new Entry(new At(new \DateTimeImmutable($at)));
                },
            ),
            state: new MemoryState(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(45.0);
        $this->assertCount(1, $scheduler->tick());
        $scheduler->commit();

        // The source still lists the row (the worker has not marked it
        // done yet): it must not re-arm.
        $scheduler->reconcile();
        $clock->advance(30.0);
        $this->assertSame([], $scheduler->tick());
        $scheduler->commit();

        // A new version is a genuine reschedule.
        $set->rows = [new Row('job', 'v2', '2026-08-18 03:01:30')];
        $scheduler->reconcile();
        $clock->advance(60.0); // 03:02:15
        $this->assertCount(1, $scheduler->tick());
        $scheduler->commit();

        // The row leaves a full snapshot: the tombstone is evicted, so a
        // later row reusing the same id and version schedules again.
        $set->rows = [];
        $scheduler->reconcile(full: true);

        $set->rows = [new Row('job', 'v2', '2026-08-18 03:03:00')];
        $scheduler->reconcile();
        $clock->advance(60.0); // 03:03:15

        $this->assertCount(1, $scheduler->tick());
    }

    public function testActiveFromStopsBackfillUnderAnOldWatermark(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));

        $set = new RowSet();
        $scheduler = new Scheduler(
            source: new Source(
                list: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            state: new MemoryState(),
            clock: $clock,
        );
        $scheduler->tick();
        $scheduler->commit(); // watermark at 03:00:00

        $set->rows = [new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:00:30.000000'))];
        $scheduler->reconcile();

        $clock->advance(120.0); // window [03:00:00, 03:02:00) — but the schedule changed at 03:00:30

        $this->assertSame(
            ['03:01:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
            'occurrences from before the schedule (last) changed are never delivered',
        );
    }

    public function testAFailedListingDiscardsTheWholeBatch(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $link = new class {
            public bool $broken = false;
        };
        $scheduler = new Scheduler(
            source: new Source(
                list: function () use ($link): iterable {
                    yield new Row('a', 'v1');
                    if ($link->broken) {
                        throw new \RuntimeException('connection lost');
                    }
                    yield new Row('b', 'v1');
                },
                make: fn(Row $row): Entry => new Entry(new Interval(60), $row->id),
            ),
            state: new MemoryState(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();

        $link->broken = true;
        try {
            $scheduler->reconcile(full: true);
            $this->fail('a failed listing must surface');
        } catch (\RuntimeException) {
        }

        $clock->advance(60.0);
        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());

        $this->assertSame(['a', 'b'], $ids, 'a failed listing must not look like a mass removal');
    }

    public function testARowThatFailsToMakeIsSkippedAndReported(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $telemetry = new TestTelemetry();
        $errors = new class {
            /** @var list<string> */
            public array $messages = [];
        };
        $scheduler = new Scheduler(
            source: new Source(
                list: fn(): array => [new Row('a', 'v1'), new Row('bad', 'v1'), new Row('c', 'v1')],
                make: function (Row $row): Entry {
                    if ($row->id === 'bad') {
                        throw new \InvalidArgumentException('poison row');
                    }

                    return new Entry(new Interval(60), $row->id);
                },
            ),
            state: new MemoryState(),
            clock: $clock,
            telemetry: $telemetry,
            onError: function (\Throwable $error) use ($errors): void {
                $errors->messages[] = $error->getMessage();
            },
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(60.0);

        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());

        $this->assertSame(['a', 'c'], $ids);
        $this->assertSame(['poison row'], $errors->messages);

        /** @var list<float|int> $counted */
        $counted = get_object_vars($telemetry->counters['schedule.error.total'])['values'];
        $this->assertSame([1], $counted);
    }
}
