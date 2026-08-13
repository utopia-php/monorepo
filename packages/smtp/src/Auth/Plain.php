<?php

declare(strict_types=1);

namespace Utopia\SMTP\Auth;

/**
 * RFC 4616, carried by RFC 4954. One round trip, and safe once TLS is up.
 */
class Plain implements Authenticator
{
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
    ) {}

    public function mechanism(): string
    {
        return 'PLAIN';
    }

    public function initial(): string
    {
        return "\0{$this->username}\0{$this->password}";
    }

    public function respond(string $challenge): string
    {
        return $this->initial();
    }
}
