<?php

declare(strict_types=1);

namespace Utopia\Queue;

readonly class Queue
{
    /**
     * @param int|null $slaSeconds Target wait time, published as telemetry by
     *        {@see Server} and acted on by nothing here: a message that waits
     *        longer is still delivered. Null publishes no target at all.
     */
    public function __construct(
        public string $name,
        public string $namespace = 'utopia-queue',
        public int $jobTtl = 0,
        public ?int $slaSeconds = null,
    ) {
        if ($this->name === '' || $this->name === '0') {
            throw new \InvalidArgumentException('Cannot create queue with empty name.');
        }

        if ($this->slaSeconds !== null && $this->slaSeconds <= 0) {
            throw new \InvalidArgumentException('Cannot create queue with a non-positive SLA.');
        }
    }
}
