<?php

declare(strict_types=1);

namespace Nats\Auth;

final class TokenAuth implements Authenticator
{
    public function __construct(
        private readonly string $token,
    ) {
    }

    public function authenticate(?string $nonce = null): array
    {
        return [
            'auth_token' => $this->token,
        ];
    }
}
