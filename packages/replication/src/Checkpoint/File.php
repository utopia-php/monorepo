<?php

namespace Utopia\Replication\Checkpoint;

use Utopia\Replication\Checkpoint;
use Utopia\Replication\Exception;

/**
 * Stores the position in a single file, written atomically (temp file + rename).
 *
 * Point it at durable storage (e.g. a mounted volume) so the position survives
 * process and host restarts. Writes are coalesced to at most one per {@see
 * $interval} seconds to keep the streaming hot path cheap; because resuming is
 * idempotent, the at-most-{interval} replay window after a crash is harmless.
 */
final class File implements Checkpoint
{
    private ?string $pending = null;
    private float $writtenAt = 0.0;

    public function __construct(
        private readonly string $path,
        private readonly float $interval = 1.0,
    ) {}

    public function get(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $value = file_get_contents($this->path);
        if ($value === false) {
            throw new Exception("Failed to read checkpoint at {$this->path}");
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function set(string $position): void
    {
        $this->pending = $position;

        if (microtime(true) - $this->writtenAt >= $this->interval) {
            $this->flush();
        }
    }

    /**
     * Force a coalesced position to disk. Call before shutting down so the latest
     * position is not left waiting in the write window.
     */
    public function flush(): void
    {
        if ($this->pending === null) {
            return;
        }

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new Exception("Failed to create checkpoint directory at {$directory}");
        }

        $temp = $this->path . '.tmp';
        if (file_put_contents($temp, $this->pending) === false || !rename($temp, $this->path)) {
            throw new Exception("Failed to write checkpoint at {$this->path}");
        }

        $this->writtenAt = microtime(true);
        $this->pending = null;
    }
}
