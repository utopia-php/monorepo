<?php

declare(strict_types=1);

namespace Utopia\Queue\Option;

readonly class Reliable
{
    public function __construct(
        public int $visibility = 30,
        public int $heartbeat = 10,
        public int $scan = 5,
        public int $batch = 100,
        public int $pollMinimum = 10,
        public int $pollMaximum = 100,
    ) {
        if ($this->visibility <= 0) {
            throw new \InvalidArgumentException('Visibility must be greater than zero.');
        }
        if ($this->heartbeat <= 0 || $this->heartbeat >= $this->visibility) {
            throw new \InvalidArgumentException('Heartbeat must be greater than zero and less than visibility.');
        }
        if ($this->scan <= 0) {
            throw new \InvalidArgumentException('Recovery scan interval must be greater than zero.');
        }
        if ($this->batch <= 0) {
            throw new \InvalidArgumentException('Recovery batch must be greater than zero.');
        }
        if ($this->pollMinimum <= 0 || $this->pollMaximum < $this->pollMinimum) {
            throw new \InvalidArgumentException('Poll bounds must be positive and ordered.');
        }
    }
}
