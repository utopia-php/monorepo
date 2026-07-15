<?php

declare(strict_types=1);

namespace Utopia\Queue;

readonly class Claim
{
    public function __construct(
        public string $pid,
        public ?string $claimedAt,
    ) {}
}
