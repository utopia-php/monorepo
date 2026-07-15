<?php

declare(strict_types=1);

namespace Utopia\Queue;

use Utopia\Queue\Option\Reliable;

readonly class Queue
{
    public function __construct(
        public string $name,
        public string $namespace = 'utopia-queue',
        public int $jobTtl = 0,
        public ?Reliable $reliable = null,
    ) {
        if ($this->name === '' || $this->name === '0') {
            throw new \InvalidArgumentException('Cannot create queue with empty name.');
        }
        if ($this->reliable instanceof Reliable && $this->jobTtl > 0) {
            throw new \InvalidArgumentException('Reliable queues do not support job TTL.');
        }
    }
}
