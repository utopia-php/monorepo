<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\At;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Cron;
use Utopia\Schedule\Entry;
use Utopia\Schedule\Interval;
use Utopia\Schedule\MemoryState;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Row;
use Utopia\Schedule\Schedule;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\TestClock;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class SchedulerTest extends TestCase
{
    public function testTickPhaseDriftingAcrossMinuteBoundariesDropsNothing(): void
    {
        // Regression for a production incident: the tick phase sat just
        // before a minute boundary and crept ~1.5ms per tick across it.
        // A "now"-based scheduler dropped ~90% of runs for 45 minutes;
        // tiled windows must deliver every occurrence exactly once.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-17 15:53:59.773000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Cron('*/5 * * * *')], interval: 60);

        $delivered = [];
        for ($tick = 0; $tick < 60; ++$tick) {
            foreach ($scheduler->tick() as $occurrence) {
                $delivered[] = $occurrence->due->format('H:i:s');
            }
            $scheduler->commit();
            $clock->advance(60.0015); // sleep(60) resumes late, never early
        }

        $expected = [];
        foreach (['15:55', '16:00', '16:05', '16:10', '16:15', '16:20', '16:25', '16:30', '16:35', '16:40', '16:45', '16:50'] as $slot) {
            $expected[] = "{$slot}:00";
        }

        $this->assertSame($expected, $delivered);
    }

    public function testRestartWithSharedStateBackfillsTheGap(): void
    {
        $state = new MemoryState();
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)], state: $state);
        $scheduler->tick();
        $scheduler->commit();

        // The process dies for 3 minutes; a replacement takes the expired
        // claim and delivers every occurrence its predecessor missed.
        $clock->advance(185.0);
        $replacement = $this->scheduler($clock, ['fn' => new Interval(60)], state: $state, token: 'replacement');

        $this->assertSame(
            ['03:01:00', '03:02:00', '03:03:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $replacement->tick()),
        );
    }

    public function testLookbackCapsTheCatchUpBurst(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)], lookback: 120);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(3600.0);

        $this->assertSame(
            ['03:59:00', '04:00:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
        );
    }

    public function testTickWithoutCommitRedelivers(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)]);
        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(60.0);

        $first = $scheduler->tick();
        $again = $scheduler->tick();

        $this->assertCount(1, $first);
        $this->assertEquals($first, $again);

        $scheduler->commit();
        $this->assertSame([], $scheduler->tick());
    }

    public function testOneShotsDeliverOnceAndAreDropped(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $scheduler = $this->scheduler($clock, ['delayed' => At::in(30, $clock->now())]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(45.0);
        $delivered = $scheduler->tick();
        $scheduler->commit();

        $this->assertSame(['delayed'], array_map(fn(Occurrence $occurrence): string => $occurrence->id, $delivered));

        $clock->advance(600.0);
        $this->assertSame([], $scheduler->tick());
    }

    public function testOccurrencesAreOrderedOldestFirstAcrossSchedules(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, [
            'b-minutely' => new Cron('* * * * *'),
            'a-half-minute' => new Interval(30),
        ]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(90.0);

        $this->assertSame(
            [
                ['a-half-minute', '03:00:30'], // exactly on the inclusive window start
                ['a-half-minute', '03:01:00'],
                ['b-minutely', '03:01:00'],
                ['a-half-minute', '03:01:30'],
            ],
            array_map(
                fn(Occurrence $occurrence): array => [$occurrence->id, $occurrence->due->format('H:i:s')],
                $scheduler->tick(),
            ),
        );
    }

    public function testWatermarkNeverRewindsWhenTheClockStepsBack(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:02:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(60.0);
        $this->assertCount(1, $scheduler->tick()); // 03:03:00
        $scheduler->commit();

        $clock->advance(-90.0);
        $this->assertSame([], $scheduler->tick());
        $scheduler->commit();

        // Real time passes the committed edge again: 03:03:00 is not
        // delivered a second time.
        $clock->advance(150.0);
        $this->assertSame(
            ['03:04:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
        );
    }

    public function testLookaheadHandsOverFutureOccurrences(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Cron('*/15 * * * *')], lookahead: 60);

        $this->assertSame([], $scheduler->tick()); // 03:15 is beyond the first window
        $scheduler->commit();

        $clock->advance(14 * 60.0); // 03:14:30
        $occurrences = $scheduler->tick();

        $this->assertCount(1, $occurrences);
        $this->assertSame('03:15:00', $occurrences[0]->due->format('H:i:s'));
        $this->assertGreaterThan($clock->now(), $occurrences[0]->due);
    }

    public function testRunDeliversOnAWallAnchoredCadenceUntilStopped(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], interval: 60);

        $seen = [];
        $scheduler->run(function (Occurrence $occurrence) use (&$seen, $scheduler, $clock): void {
            $seen[] = [$occurrence->due->format('H:i:s'), $clock->now()->format('H:i:s')];
            if (\count($seen) === 2) {
                $scheduler->stop();
            }
        });

        // Each occurrence is handled on the tick after its minute closes,
        // and ticks land on wall-clock minute boundaries.
        $this->assertSame(
            [['00:01:00', '00:02:00'], ['00:02:00', '00:03:00']],
            $seen,
        );
    }

    public function testStandbyTakesOverWhenTheClaimExpires(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $state = new MemoryState();
        // Another instance holds the claim for the next 120 seconds.
        $state->swap(null, new Claim('incumbent', (float) $clock->now()->format('U') + 120.0, null));

        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], state: $state, interval: 60, token: 'standby');

        $delivered = [];
        $scheduler->run(function (Occurrence $occurrence) use (&$delivered, $scheduler): void {
            $delivered[] = $occurrence->due->format('H:i:s');
            $scheduler->stop();
        });

        // Idle at 00:00:30 and 00:01:30; the claim expires at 00:02:30, so
        // the first covered minute is 00:03.
        $this->assertSame(['00:03:00'], $delivered);

        // stop() released the claim but kept the watermark, so the next
        // instance resumes coverage instead of waiting out the lease.
        $claim = $state->load();
        $this->assertInstanceOf(\Utopia\Schedule\Claim::class, $claim);
        $this->assertSame('', $claim->token);
        $this->assertNotNull($claim->windowEnd);
    }

    public function testDeposedLeaderCommitIsFenced(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $state = new MemoryState();

        $leader = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], state: $state, token: 'leader');
        $leader->tick();
        $leader->commit(); // claim held, expires at 03:01:30

        $clock->advance(60.0); // 03:01:30
        $inFlight = $leader->tick(); // delivers 03:01:00, not yet committed
        $this->assertCount(1, $inFlight);

        // The leader stalls past its lease; a standby takes over and
        // commits further coverage.
        $clock->advance(61.0); // 03:02:31
        $standby = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], state: $state, token: 'standby');
        $this->assertSame(
            ['03:01:00', '03:02:00'], // the handover re-covers the in-flight window: duplicates, never losses
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $standby->tick()),
        );
        $standby->commit();
        $watermark = $state->load()?->windowEnd;

        // The deposed leader's late commit is fenced: no write, no rewind.
        $leader->commit();
        $this->assertSame($watermark, $state->load()?->windowEnd);
        $this->assertSame('standby', $state->load()?->token);
        $this->assertSame([], $leader->tick(), 'a deposed leader must not dispatch');
    }

    public function testGoldenSignalsAreRecorded(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $telemetry = new TestTelemetry();
        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], interval: 60, telemetry: $telemetry);

        $scheduler->run(function () use ($scheduler): void {
            $scheduler->stop();
        });

        /** @var list<float|int> $dispatched */
        $dispatched = get_object_vars($telemetry->counters['schedule.dispatch.total'])['values'];
        $this->assertSame([1], $dispatched);

        /** @var list<float|int> $delays */
        $delays = get_object_vars($telemetry->histograms['schedule.dispatch.delay'])['values'];
        $this->assertCount(1, $delays);
        // The 00:01:00 occurrence is handed over on the 00:02:00 tick.
        $this->assertEqualsWithDelta(60.0, $delays[0], 0.001);

        /** @var list<float|int> $entries */
        $entries = get_object_vars($telemetry->gauges['schedule.entries'])['values'];
        $this->assertContains(1, $entries);

        /** @var list<float|int> $lags */
        $lags = get_object_vars($telemetry->gauges['schedule.lag'])['values'];
        // Saturation reads steady at about one interval behind "now".
        $this->assertEqualsWithDelta(60.0, max([0.0, ...$lags]), 0.001);

        $this->assertArrayHasKey('schedule.tick.duration', $telemetry->histograms);
        $this->assertArrayHasKey('schedule.reconcile.duration', $telemetry->histograms);
    }

    public function testAFailingHandlerCountsADispatchErrorAndSkipsCommit(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $telemetry = new TestTelemetry();
        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], interval: 60, telemetry: $telemetry);

        try {
            $scheduler->run(function (): never {
                throw new \RuntimeException('downstream unavailable');
            });
            $this->fail('the handler failure must propagate');
        } catch (\RuntimeException) {
        }

        /** @var list<float|int> $errors */
        $errors = get_object_vars($telemetry->counters['schedule.error.total'])['values'];
        $this->assertSame([1], $errors);

        // The failed tick was never committed: it re-delivers.
        $this->assertCount(1, $scheduler->tick());
    }

    public function testRejectsNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(source: $this->emptySource(), interval: 0);
    }

    public function testRejectsALeaseShorterThanTwoTicks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(source: $this->emptySource(), interval: 30, lease: 45);
    }

    public function testSourceRejectsNonPositiveSyncCadence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Source(list: fn(): array => [], make: fn(Row $row): Entry => new Entry(new Interval(60)), every: 0);
    }

    /**
     * @param array<string, Schedule> $schedules
     */
    private function scheduler(
        TestClock $clock,
        array $schedules,
        ?MemoryState $state = null,
        int $interval = 1,
        int $lookahead = 0,
        int $lookback = 300,
        ?string $token = null,
        ?TestTelemetry $telemetry = null,
    ): Scheduler {
        $rows = [];
        foreach (array_keys($schedules) as $id) {
            $rows[] = new Row($id, '1');
        }

        $scheduler = new Scheduler(
            source: new Source(
                list: fn(): array => $rows,
                make: fn(Row $row): Entry => new Entry($schedules[$row->id]),
            ),
            state: $state ?? new MemoryState(),
            clock: $clock,
            interval: $interval,
            lookahead: $lookahead,
            lookback: $lookback,
            lease: max(60, $interval * 4),
            token: $token,
            telemetry: $telemetry ?? new \Utopia\Telemetry\Adapter\None(),
        );
        $scheduler->reconcile();

        return $scheduler;
    }

    private function emptySource(): Source
    {
        return new Source(list: fn(): array => [], make: fn(Row $row): Entry => new Entry(new Interval(60)));
    }
}
