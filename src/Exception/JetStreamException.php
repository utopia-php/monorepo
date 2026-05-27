<?php

declare(strict_types=1);

namespace Nats\Exception;

use Nats\JetStream\ApiError;

class JetStreamException extends NatsException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?ApiError $apiError = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
