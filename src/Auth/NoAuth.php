<?php

declare(strict_types=1);

namespace Nats\Auth;

final class NoAuth implements Authenticator
{
    public function authenticate(?string $nonce = null): array
    {
        return [];
    }
}
