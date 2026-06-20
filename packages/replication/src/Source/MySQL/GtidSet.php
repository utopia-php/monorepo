<?php

namespace Utopia\Replication\Source\MySQL;

/**
 * A MySQL GTID set: a map of source UUIDs to their executed transaction
 * intervals. Used both to tell the server where to resume a dump and as the
 * checkpoint token handed back to callers.
 *
 * Text form: `uuid:1-5:7-9,uuid2:1-3` (interval bounds are inclusive).
 *
 * Pure (no I/O) — unit-testable.
 */
class GtidSet
{
    /**
     * Lower-cased UUID => sorted list of inclusive [start, end] intervals.
     *
     * @var array<string, list<array{int, int}>>
     */
    private array $sids = [];

    public function __construct(string $gtidSet = '')
    {
        $gtidSet = trim($gtidSet);
        if ($gtidSet === '') {
            return;
        }

        foreach (explode(',', $gtidSet) as $entry) {
            $parts = explode(':', trim($entry));
            $sid = strtolower(array_shift($parts));

            foreach ($parts as $interval) {
                if (str_contains($interval, '-')) {
                    [$start, $end] = explode('-', $interval, 2);
                } else {
                    $start = $end = $interval;
                }

                $this->addInterval($sid, (int) $start, (int) $end);
            }
        }
    }

    /**
     * Add a single transaction (sid:gno), merging it into existing intervals.
     */
    public function add(string $sid, int $gno): void
    {
        $this->addInterval(strtolower($sid), $gno, $gno);
    }

    private function addInterval(string $sid, int $start, int $end): void
    {
        $intervals = $this->sids[$sid] ?? [];
        $intervals[] = [$start, $end];

        usort($intervals, fn(array $a, array $b) => $a[0] <=> $b[0]);

        /** @var list<array{int, int}> $merged */
        $merged = [];
        foreach ($intervals as $interval) {
            $index = \count($merged) - 1;
            if ($index >= 0 && $interval[0] <= $merged[$index][1] + 1) {
                $merged[$index] = [$merged[$index][0], max($merged[$index][1], $interval[1])];
            } else {
                $merged[] = [$interval[0], $interval[1]];
            }
        }

        $this->sids[$sid] = $merged;
    }

    public function isEmpty(): bool
    {
        return $this->sids === [];
    }

    public function __toString(): string
    {
        $entries = [];
        foreach ($this->sids as $sid => $intervals) {
            $parts = [$sid];
            foreach ($intervals as [$start, $end]) {
                $parts[] = $start === $end ? (string) $start : "{$start}-{$end}";
            }
            $entries[] = implode(':', $parts);
        }

        return implode(',', $entries);
    }

    /**
     * Encode for COM_BINLOG_DUMP_GTID: the wire form uses half-open intervals
     * (`end` is the last transaction + 1).
     */
    public function encode(): string
    {
        $payload = pack('P', \count($this->sids));

        foreach ($this->sids as $sid => $intervals) {
            $payload .= hex2bin(str_replace('-', '', $sid));
            $payload .= pack('P', \count($intervals));
            foreach ($intervals as [$start, $end]) {
                $payload .= pack('P', $start);
                $payload .= pack('P', $end + 1);
            }
        }

        return $payload;
    }
}
