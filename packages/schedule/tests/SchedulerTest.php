<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\At;
use Utopia\Schedule\Cron;
use Utopia\Schedule\Interval;
use Utopia\Schedule\MemoryState;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\TestClock;

final class SchedulerTest extends TestCase
{
    public function testTickPhaseDriftingAcrossMinuteBoundariesDropsNothing(): void
    {
        // Regression for a production incident: the tick phase sat just
        // before a minute boundary and crept ~1.5ms per tick across it.
        // A "now"-based scheduler dropped ~90% of runs for 45 minutes;
        // tiled windows must deliver every occurrence exactly once.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-17 15:53:59.773000'));
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock, interval: 60);
        $scheduler->set('fn', new Cron('*/5 * * * *'));

        $delivered = [];
        for ($tick = 0; $tick < 60; $tick++) {
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

        $scheduler = new Scheduler(state: $state, clock: $clock);
        $scheduler->set('fn', new Interval(60));
        $scheduler->tick();
        $scheduler->commit();

        // The process dies for 3 minutes; a replacement resumes from the
        // shared watermark and delivers every occurrence it missed.
        $clock->advance(185.0);
        $replacement = new Scheduler(state: $state, clock: $clock);
        $replacement->set('fn', new Interval(60));

        $this->assertSame(
            ['03:01:00', '03:02:00', '03:03:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $replacement->tick()),
        );
    }

    public function testLookbackCapsTheCatchUpBurst(): void
    {
        $state = new MemoryState();
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $scheduler = new Scheduler(state: $state, clock: $clock, lookback: 120);
        $scheduler->set('fn', new Interval(60));
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
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock);
        $scheduler->set('fn', new Interval(60));
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
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock);
        $scheduler->set('delayed', At::in(30, $clock->now()));
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(45.0);
        $delivered = $scheduler->tick();
        $scheduler->commit();

        $this->assertSame(['delayed'], array_map(fn(Occurrence $occurrence): string => $occurrence->id, $delivered));

        $clock->advance(600.0);
        $this->assertSame([], $scheduler->tick());
    }

    public function testRemovedSchedulesStopFiring(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock);
        $scheduler->set('fn', new Interval(60));
        $scheduler->tick();
        $scheduler->commit();

        $scheduler->remove('fn');
        $clock->advance(120.0);

        $this->assertSame([], $scheduler->tick());
    }

    public function testOccurrencesAreOrderedOldestFirstAcrossSchedules(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock);
        $scheduler->set('b-minutely', new Cron('* * * * *'));
        $scheduler->set('a-half-minute', new Interval(30));
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
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock);
        $scheduler->set('fn', new Interval(60));
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
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock, lookahead: 60);
        $scheduler->set('fn', new Cron('*/15 * * * *'));

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
        $scheduler = new Scheduler(state: new MemoryState(), clock: $clock, interval: 60);
        $scheduler->set('minutely', new Cron('* * * * *'));

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

    public function testRejectsNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(interval: 0);
    }
}
