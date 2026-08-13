<?php

declare(strict_types=1);

namespace Utopia\SMTP\Auth;

/**
 * One RFC 4954 mechanism. The client tries its authenticators in order against
 * what the server advertises, so the order is the security policy.
 */
interface Authenticator
{
    /**
     * The name as it appears in the AUTH capability, e.g. PLAIN.
     */
    public function mechanism(): string;

    /**
     * The initial response sent with the AUTH command, saving a round trip.
     * Null when the mechanism has to wait for the server to speak first.
     *
     * Returned decoded; the client applies base64.
     *
     * Called exactly once at the start of every exchange, so a mechanism that
     * carries state between challenges resets it here. An authenticator outlives
     * the connection it was built for, and is reused after a reconnect.
     */
    public function initial(): ?string;

    /**
     * Answer a server challenge, decoded in and decoded out.
     */
    public function respond(string $challenge): string;
}
