<?php

declare(strict_types=1);

namespace Nats\JetStream;

final class ConsumerInfo
{
    public function __construct(
        public readonly string $streamName,
        public readonly string $name,
        public readonly ConsumerConfig $config,
        public readonly string $created,
        public readonly int $numAckPending,
        public readonly int $numRedelivered,
        public readonly int $numWaiting,
        public readonly int $numPending,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            streamName: $data['stream_name'] ?? '',
            name: $data['name'] ?? '',
            config: ConsumerConfig::fromArray($data['config'] ?? []),
            created: $data['created'] ?? '',
            numAckPending: $data['num_ack_pending'] ?? 0,
            numRedelivered: $data['num_redelivered'] ?? 0,
            numWaiting: $data['num_waiting'] ?? 0,
            numPending: $data['num_pending'] ?? 0,
        );
    }
}
