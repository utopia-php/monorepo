<?php

declare(strict_types=1);

namespace Nats\JetStream;

use Nats\Connection;
use Nats\Headers;
use Nats\Message;

final class JetStreamMessage
{
    private ?MsgMetadata $metadata = null;

    public function __construct(
        private readonly Connection $conn,
        public readonly Message $message,
    ) {
    }

    public function ack(): void
    {
        $this->respond('');
    }

    public function nak(?float $delay = null): void
    {
        if ($delay !== null) {
            $nanos = StreamConfig::secondsToNanos($delay);
            $this->respond("-NAK {\"delay\":{$nanos}}");
        } else {
            $this->respond('-NAK');
        }
    }

    public function inProgress(): void
    {
        $this->respond('+WPI');
    }

    public function term(?string $reason = null): void
    {
        if ($reason !== null) {
            $this->respond("+TERM {$reason}");
        } else {
            $this->respond('+TERM');
        }
    }

    public function metadata(): MsgMetadata
    {
        if ($this->metadata === null) {
            if ($this->message->replyTo === null) {
                throw new \RuntimeException('Message has no reply subject for metadata parsing');
            }
            $this->metadata = MsgMetadata::fromReplySubject($this->message->replyTo);
        }

        return $this->metadata;
    }

    public function getData(): string
    {
        return $this->message->data;
    }

    public function getSubject(): string
    {
        return $this->message->subject;
    }

    public function getHeaders(): ?Headers
    {
        return $this->message->headers;
    }

    private function respond(string $data): void
    {
        if ($this->message->replyTo === null) {
            throw new \RuntimeException('Cannot acknowledge: message has no reply subject');
        }

        $this->conn->publish($this->message->replyTo, $data);
    }
}
