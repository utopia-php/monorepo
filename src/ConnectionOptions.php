<?php

declare(strict_types=1);

namespace Nats;

final class ConnectionOptions
{
    /** @var list<string> */
    public readonly array $servers;

    /**
     * @param string|list<string> $servers
     */
    public function __construct(
        string|array $servers = 'nats://127.0.0.1:4222',
        public readonly string $name = '',
        // Auth
        public readonly ?string $user = null,
        public readonly ?string $pass = null,
        public readonly ?string $token = null,
        public readonly ?string $nkey = null,
        public readonly ?string $nkeySeed = null,
        public readonly ?string $credentialsFile = null,
        // TLS
        public readonly bool $tls = false,
        public readonly ?string $tlsCaFile = null,
        public readonly ?string $tlsCertFile = null,
        public readonly ?string $tlsKeyFile = null,
        // Reconnection
        public readonly bool $allowReconnect = true,
        public readonly int $maxReconnectAttempts = 60,
        public readonly float $reconnectWait = 2.0,
        public readonly float $reconnectJitter = 0.1,
        // Timeouts
        public readonly float $connectTimeout = 2.0,
        public readonly float $requestTimeout = 5.0,
        public readonly float $drainTimeout = 30.0,
        // PING/PONG
        public readonly float $pingInterval = 120.0,
        public readonly int $maxPingsOut = 2,
        // Misc
        public readonly bool $verbose = false,
        public readonly bool $pedantic = false,
        public readonly bool $echo = true,
        public readonly bool $noRandomize = false,
        public readonly string $inboxPrefix = '_INBOX',
        // Callbacks
        public readonly ?\Closure $onDisconnect = null,
        public readonly ?\Closure $onReconnect = null,
        public readonly ?\Closure $onClose = null,
        public readonly ?\Closure $onError = null,
    ) {
        $this->servers = is_string($servers) ? [$servers] : $servers;
    }
}
