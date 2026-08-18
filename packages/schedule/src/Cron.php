<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Cron\CronExpression;

/**
 * Recurring schedule described by a five-field cron expression.
 *
 * Occurrences have minute resolution. Invalid and impossible expressions
 * are rejected at construction, not discovered as a silent no-op at
 * evaluation time.
 */
final readonly class Cron implements Schedule
{
    private CronExpression $expression;

    /**
     * @throws \InvalidArgumentException when the expression cannot parse or never matches a date
     */
    public function __construct(string $expression)
    {
        $this->expression = new CronExpression($expression);

        try {
            $this->expression->getNextRunDate();
        } catch (\RuntimeException $error) {
            throw new \InvalidArgumentException("Cron expression \"{$expression}\" never matches a date", 0, $error);
        }
    }

    #[\Override]
    public function occurrencesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $occurrences = [];

        try {
            // allowCurrentDate keeps an occurrence exactly at the window
            // start inside this window; the guard below discards the
            // sub-minute case where the truncated match precedes it.
            $due = \DateTimeImmutable::createFromMutable($this->expression->getNextRunDate($start, 0, true));

            if ($due < $start) {
                $due = \DateTimeImmutable::createFromMutable($this->expression->getNextRunDate($due));
            }

            while ($due < $end) {
                $occurrences[] = $due;
                $due = \DateTimeImmutable::createFromMutable($this->expression->getNextRunDate($due));
            }
        } catch (\RuntimeException) {
            // no further matches inside the library's search horizon
        }

        return $occurrences;
    }

    #[\Override]
    public function recurring(): bool
    {
        return true;
    }
}
