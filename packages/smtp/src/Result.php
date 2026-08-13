<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * What one send achieved.
 *
 * RFC 5321 lets a server refuse some recipients and accept others, and the
 * message still reaches the rest, so this is not a boolean.
 */
class Result
{
    /**
     * @param  string  $messageId  Read out of the final reply. Empty when the server did not offer one.
     * @param  list<string>  $accepted
     * @param  array<string, Reply>  $rejected
     */
    public function __construct(
        public readonly string $messageId,
        public readonly array $accepted,
        public readonly array $rejected,
    ) {}

    public function isComplete(): bool
    {
        return $this->rejected === [];
    }
}
