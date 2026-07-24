<?php

declare(strict_types=1);

namespace Utopia\Queue;

readonly class Queue
{
    public function __construct(
        public string $name,
        public string $namespace = 'utopia-queue',
        public int $jobTtl = 0,
        public int $visibilityTimeout = 0,
    ) {
        if ($this->name === '' || $this->name === '0') {
            throw new \InvalidArgumentException('Cannot create queue with empty name.');
        }

        if ($this->visibilityTimeout < 0) {
            throw new \InvalidArgumentException('Visibility timeout cannot be negative.');
        }
    }
}
