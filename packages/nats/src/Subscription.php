<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class Subscription
{
    private readonly \SplQueue $pendingMessages;
    private bool $active = true;
    private ?int $maxMessages = null;
    private int $received = 0;

    /** @var Connection|null Back-reference for sync operations */
    private ?Connection $connection = null;

    public function __construct(
        public readonly string $sid,
        public readonly string $subject,
        public readonly ?string $queue = null,
        private readonly ?\Closure $callback = null,
    ) {
        $this->pendingMessages = new \SplQueue();
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    public function nextMessage(?float $timeout = null): ?Message
    {
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while (true) {
            // Drain anything already queued before blocking on the socket.
            if (!$this->pendingMessages->isEmpty()) {
                return $this->pendingMessages->dequeue();
            }

            if (!$this->active || !$this->connection instanceof \Utopia\NATS\Connection) {
                return null;
            }

            $remaining = $deadline !== null ? $deadline - microtime(true) : null;
            if ($remaining !== null && $remaining <= 0) {
                return null;
            }

            $this->connection->processMessage($remaining);
        }
    }

    public function unsubscribe(?int $afterMessages = null): void
    {
        if ($this->connection instanceof \Utopia\NATS\Connection) {
            $this->connection->unsubscribe($this, $afterMessages);
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deliver(Message $msg): void
    {
        $this->received++;

        if ($this->callback instanceof \Closure) {
            ($this->callback)($msg);
        } else {
            $this->pendingMessages->enqueue($msg);
        }

        if ($this->maxMessages !== null && $this->received >= $this->maxMessages) {
            $this->active = false;
        }
    }

    public function setMaxMessages(int $max): void
    {
        $this->maxMessages = $max;
        if ($this->received >= $max) {
            $this->active = false;
        }
    }

    public function setInactive(): void
    {
        $this->active = false;
    }

    public function getReceived(): int
    {
        return $this->received;
    }

    public function hasCallback(): bool
    {
        return $this->callback instanceof \Closure;
    }
}
