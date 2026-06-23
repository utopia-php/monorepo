<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class Subscription
{
    private \SplQueue $pendingMessages;
    private bool $active = true;
    private ?int $maxMessages = null;
    private int $received = 0;

    /** @var \Closure|null */
    private ?\Closure $callback;

    /** @var Connection|null Back-reference for sync operations */
    private ?Connection $connection = null;

    public function __construct(
        public readonly string $sid,
        public readonly string $subject,
        public readonly ?string $queue = null,
        ?\Closure $callback = null,
    ) {
        $this->callback = $callback;
        $this->pendingMessages = new \SplQueue();
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    public function nextMessage(?float $timeout = null): ?Message
    {
        if (!$this->active) {
            return null;
        }

        // Return from pending queue first
        if (!$this->pendingMessages->isEmpty()) {
            return $this->pendingMessages->dequeue();
        }

        // Block and process messages until one arrives for this subscription
        if ($this->connection === null) {
            return null;
        }

        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while ($this->active) {
            $remaining = $deadline !== null ? $deadline - microtime(true) : null;
            if ($remaining !== null && $remaining <= 0) {
                return null;
            }

            $this->connection->processMessage($remaining);

            if (!$this->pendingMessages->isEmpty()) {
                return $this->pendingMessages->dequeue();
            }
        }

        return null;
    }

    public function unsubscribe(?int $afterMessages = null): void
    {
        if ($this->connection !== null) {
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

        if ($this->callback !== null) {
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
        return $this->callback !== null;
    }
}
