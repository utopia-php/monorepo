<?php

declare(strict_types=1);

namespace Utopia\SMTP\Auth;

/**
 * The undocumented mechanism every server implements anyway: username, then
 * password, each in answer to a prompt whose wording nobody agrees on.
 */
class Login implements Authenticator
{
    private bool $sentUsername = false;

    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
    ) {}

    public function mechanism(): string
    {
        return 'LOGIN';
    }

    public function initial(): ?string
    {
        return null;
    }

    public function respond(string $challenge): string
    {
        if ($this->sentUsername) {
            return $this->password;
        }

        $this->sentUsername = true;

        return $this->username;
    }
}
