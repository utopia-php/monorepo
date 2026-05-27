<?php

declare(strict_types=1);

namespace Nats\KeyValue;

use Nats\JetStream\DiscardPolicy;
use Nats\JetStream\RetentionPolicy;
use Nats\JetStream\StorageType;
use Nats\JetStream\StreamConfig;

final class KeyValueConfig
{
    /**
     * @param float|null $ttl TTL in seconds
     */
    public function __construct(
        public readonly string $bucket,
        public readonly ?string $description = null,
        public readonly int $maxValueSize = -1,
        public readonly int $history = 1,
        public readonly ?float $ttl = null,
        public readonly int $maxBytes = -1,
        public readonly StorageType $storage = StorageType::File,
        public readonly int $replicas = 1,
    ) {
    }

    public function toStreamConfig(): StreamConfig
    {
        return new StreamConfig(
            name: "KV_{$this->bucket}",
            subjects: ["\$KV.{$this->bucket}.>"],
            description: $this->description,
            retention: RetentionPolicy::Limits,
            maxMsgsPerSubject: $this->history,
            maxBytes: $this->maxBytes,
            maxMsgSize: $this->maxValueSize > 0 ? $this->maxValueSize : null,
            maxAge: $this->ttl,
            storage: $this->storage,
            replicas: $this->replicas,
            discard: DiscardPolicy::New,
            allowRollup: true,
            allowDirect: true,
            mirrorDirect: true,
        );
    }
}
