<?php

declare(strict_types=1);

namespace Utopia\Queue\Consumer;

use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

interface Leased extends Consumer
{
    /**
     * Extends an active message's visibility lease.
     *
     * Returns false when visibility leases are disabled, the lease expired,
     * or the message belongs to an older delivery.
     */
    public function renew(Queue $queue, Message $message): bool;
}
