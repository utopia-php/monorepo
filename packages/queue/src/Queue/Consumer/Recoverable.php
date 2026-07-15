<?php

declare(strict_types=1);

namespace Utopia\Queue\Consumer;

use Utopia\Queue\Claim;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

interface Recoverable
{
    public function extend(Queue $queue, Message $message): bool;

    /** @return list<Claim> */
    public function expired(Queue $queue, int $limit): array;

    public function reclaim(Queue $queue, Claim $claim): ?Message;
}
