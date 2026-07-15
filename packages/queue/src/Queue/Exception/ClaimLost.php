<?php

declare(strict_types=1);

namespace Utopia\Queue\Exception;

final class ClaimLost extends \RuntimeException
{
    public function __construct(
        public readonly string $pid,
        public readonly string $claimedAt,
    ) {
        parent::__construct("Reliable queue claim {$pid} ({$claimedAt}) is no longer owned.");
    }
}
