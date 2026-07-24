<?php

declare(strict_types=1);

namespace Utopia\Queue\Exception;

final class Conflict extends \LogicException
{
    public function __construct(
        public readonly string $messageId,
    ) {
        parent::__construct("Queue message ID '{$messageId}' is already associated with a different envelope.");
    }
}
