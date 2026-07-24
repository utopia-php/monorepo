<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

interface Idempotent extends Publisher
{
    /**
     * Publishes an envelope once for a caller-stable message ID.
     *
     * Both result cases acknowledge success. Reusing the message ID for a
     * different canonical payload or priority throws a Conflict exception.
     */
    public function enqueueOnce(
        Queue $queue,
        string $messageId,
        array $payload,
        bool $priority = false,
    ): Result;
}
