<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * How the handshake is checked. Shared by every transport so that verification
 * cannot drift between them.
 */
class Tls
{
    /**
     * @param  string|null  $peerName  The name the certificate must match. Defaults to the host being dialled.
     * @param  string|null  $caFile  A certificate authority bundle, when the system store is not the right answer.
     */
    public function __construct(
        public readonly bool $verifyPeer = true,
        public readonly ?string $peerName = null,
        public readonly ?string $caFile = null,
        public readonly ?string $ciphers = null,
    ) {}
}
