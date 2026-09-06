<?php

declare(strict_types=1);

namespace Utopia\Reputation;

use MaxMind\Db\Reader;

/**
 * In-process IP-reputation lookups against a Verdict MMDB.
 *
 * Constructing this object, and {@see get()}, do not open the database.
 * The file is memory-mapped on the first field read of a {@see Record} and
 * reopened when the file's mtime changes, so a replaced MMDB is picked up
 * without a process restart.
 *
 * @see \Utopia\Reputation\Tests\ReputationTest
 */
final class Reputation
{
    private ?Reader $reader = null;

    private int $mtime = 0;

    /**
     * @param callable(string): mixed|null $lookup
     *        Optional override used instead of the MMDB (tests and custom sources).
     */
    public function __construct(
        private readonly string $path,
        private readonly mixed $lookup = null,
    ) {}

    public function get(string $ip): Record
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return Record::clean($ip);
        }

        return Record::pending($ip, fn(): mixed => $this->payload($ip));
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function payload(string $ip): ?array
    {
        $lookup = $this->lookup;
        if (\is_callable($lookup)) {
            $payload = $lookup($ip);

            return \is_array($payload) ? $payload : null;
        }

        return $this->readMmdb($ip);
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function readMmdb(string $ip): ?array
    {
        $reader = $this->reader();
        if (! $reader instanceof Reader) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($record) ? $record : null;
    }

    private function reader(): ?Reader
    {
        $mtime = @filemtime($this->path);
        if ($mtime === false) {
            $this->close();

            return null;
        }

        if ($this->reader instanceof Reader && $this->mtime === $mtime) {
            return $this->reader;
        }

        $this->close();

        try {
            $this->reader = new Reader($this->path);
            $this->mtime = $mtime;

            return $this->reader;
        } catch (\Throwable) {
            return null;
        }
    }

    private function close(): void
    {
        if (! $this->reader instanceof Reader) {
            return;
        }

        try {
            $this->reader->close();
        } catch (\Throwable) {
        }

        $this->reader = null;
        $this->mtime = 0;
    }
}
