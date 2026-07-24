<?php

namespace Utopia\Queue;

class Message
{
    protected string $pid;
    protected string $queue;
    protected int $timestamp;
    protected array $payload;
    protected ?string $receipt = null;

    public function __construct(array $array = [])
    {
        if ($array === []) {
            return;
        }

        $this->pid = $array['pid'];
        $this->queue = $array['queue'];
        $this->timestamp = $array['timestamp'];
        $this->payload = $array['payload'] ?? [];
        $this->receipt = $array['receipt'] ?? null;
    }

    public function setPid(string $pid): self
    {
        $this->pid = $pid;

        return $this;
    }

    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getPid(): string
    {
        return $this->pid;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setReceipt(?string $receipt): self
    {
        $this->receipt = $receipt;

        return $this;
    }

    public function getReceipt(): ?string
    {
        return $this->receipt;
    }

    public function asArray(): array
    {
        $message = [
            'pid' => $this->pid,
            'queue' => $this->queue,
            'timestamp' => $this->timestamp,
            'payload' => $this->payload ?? null,
        ];

        if ($this->receipt !== null) {
            $message['receipt'] = $this->receipt;
        }

        return $message;
    }
}
