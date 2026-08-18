<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\At;
use Utopia\Schedule\Cron;
use Utopia\Schedule\Interval;

final class ScheduleTest extends TestCase
{
    public function testCronOccurrenceOnWindowStartBelongsToTheWindow(): void
    {
        $cron = new Cron('*/15 * * * *');

        $this->assertSame(
            ['16:00:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 16:00:00.000000'),
                new \DateTimeImmutable('2026-08-17 16:01:00.000000'),
            )),
        );
    }

    public function testCronOccurrenceOnWindowEndBelongsToTheNextWindow(): void
    {
        $cron = new Cron('*/15 * * * *');

        $this->assertSame([], $cron->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 15:45:00.000001'),
            new \DateTimeImmutable('2026-08-17 16:00:00.000000'),
        ));
    }

    public function testCronBoundaryOccurrenceSurvivesSubSecondWindowStart(): void
    {
        // The window opens 59 milliseconds before a minute boundary — the
        // exact shape of a tick racing the wall clock.
        $cron = new Cron('*/15 * * * *');

        $this->assertSame(
            ['16:00:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 15:59:59.941000'),
                new \DateTimeImmutable('2026-08-17 16:00:59.941000'),
            )),
        );
    }

    public function testCronEnumeratesEveryOccurrenceInAWideWindow(): void
    {
        $cron = new Cron('*/15 * * * *');

        $this->assertSame(
            ['03:15:00', '03:30:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-18 03:00:59.500000'),
                new \DateTimeImmutable('2026-08-18 03:31:00.500000'),
            )),
        );
    }

    public function testCronRejectsInvalidExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cron('not a cron');
    }

    public function testCronRejectsImpossibleExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cron('0 0 31 2 *');
    }

    public function testIntervalOccurrencesSitOnTheEpochGrid(): void
    {
        $interval = new Interval(900);

        $this->assertSame(
            ['16:15:00', '16:30:00'],
            $this->format($interval->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 16:07:00.000000'),
                new \DateTimeImmutable('2026-08-17 16:35:00.000000'),
            )),
        );
    }

    public function testIntervalAnchorSetsThePhase(): void
    {
        $interval = new Interval(900, new \DateTimeImmutable('2026-08-17 16:07:30.000000'));

        $this->assertSame(
            ['16:22:30', '16:37:30'],
            $this->format($interval->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 16:08:00.000000'),
                new \DateTimeImmutable('2026-08-17 16:40:00.000000'),
            )),
        );
    }

    public function testIntervalOccurrenceOnWindowStartBelongsToTheWindow(): void
    {
        $interval = new Interval(60);

        $occurrences = $interval->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:07:00.000000'),
            new \DateTimeImmutable('2026-08-17 16:07:30.000000'),
        );

        $this->assertSame(['16:07:00'], $this->format($occurrences));
    }

    public function testIntervalRejectsSubSecondCadence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Interval(0);
    }

    public function testAtFiresInsideItsWindowOnly(): void
    {
        $at = new At(new \DateTimeImmutable('2026-08-17 16:00:30.000000'));

        $this->assertSame(['16:00:30'], $this->format($at->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:00:00.000000'),
            new \DateTimeImmutable('2026-08-17 16:01:00.000000'),
        )));

        $this->assertSame([], $at->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:01:00.000000'),
            new \DateTimeImmutable('2026-08-17 16:02:00.000000'),
        ));
    }

    public function testInIsAnchoredToItsCreationTime(): void
    {
        $at = At::in(30, new \DateTimeImmutable('2026-08-17 16:00:00.000000'));

        $this->assertSame(['16:00:30'], $this->format($at->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:00:29.000000'),
            new \DateTimeImmutable('2026-08-17 16:00:31.000000'),
        )));
    }

    /**
     * @param list<\DateTimeImmutable> $occurrences
     * @return list<string>
     */
    private function format(array $occurrences): array
    {
        return array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('H:i:s'), $occurrences);
    }
}
