<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class StreamConfig
{
    /**
     * @param list<string> $subjects
     * @param float|null $maxAge Max age in seconds (converted to nanoseconds for wire)
     * @param float|null $duplicateWindow Duplicate window in seconds
     */
    public function __construct(
        public readonly string $name,
        public readonly array $subjects = [],
        public readonly ?string $description = null,
        public readonly RetentionPolicy $retention = RetentionPolicy::Limits,
        public readonly int $maxConsumers = -1,
        public readonly int $maxMsgs = -1,
        public readonly int $maxBytes = -1,
        public readonly int $maxMsgsPerSubject = -1,
        public readonly ?int $maxMsgSize = null,
        public readonly ?float $maxAge = null,
        public readonly StorageType $storage = StorageType::File,
        public readonly int $replicas = 1,
        public readonly DiscardPolicy $discard = DiscardPolicy::Old,
        public readonly bool $noAck = false,
        public readonly ?float $duplicateWindow = null,
        public readonly bool $allowDirect = false,
        public readonly bool $mirrorDirect = false,
        public readonly bool $sealed = false,
        public readonly bool $denyDelete = false,
        public readonly bool $denyPurge = false,
        public readonly bool $allowRollup = false,
    ) {}

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'retention' => $this->retention->value,
            'max_consumers' => $this->maxConsumers,
            'max_msgs' => $this->maxMsgs,
            'max_bytes' => $this->maxBytes,
            'max_msgs_per_subject' => $this->maxMsgsPerSubject,
            'storage' => $this->storage->value,
            'num_replicas' => $this->replicas,
            'discard' => $this->discard->value,
            'no_ack' => $this->noAck,
            'allow_direct' => $this->allowDirect,
            'mirror_direct' => $this->mirrorDirect,
            'sealed' => $this->sealed,
            'deny_delete' => $this->denyDelete,
            'deny_purge' => $this->denyPurge,
            'allow_rollup_hdrs' => $this->allowRollup,
        ];

        if ($this->subjects !== []) {
            $data['subjects'] = $this->subjects;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->maxMsgSize !== null) {
            $data['max_msg_size'] = $this->maxMsgSize;
        }
        if ($this->maxAge !== null) {
            $data['max_age'] = self::secondsToNanos($this->maxAge);
        }
        if ($this->duplicateWindow !== null) {
            $data['duplicate_window'] = self::secondsToNanos($this->duplicateWindow);
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            subjects: $data['subjects'] ?? [],
            description: $data['description'] ?? null,
            retention: RetentionPolicy::tryFrom($data['retention'] ?? '') ?? RetentionPolicy::Limits,
            maxConsumers: $data['max_consumers'] ?? -1,
            maxMsgs: $data['max_msgs'] ?? -1,
            maxBytes: $data['max_bytes'] ?? -1,
            maxMsgsPerSubject: $data['max_msgs_per_subject'] ?? -1,
            maxMsgSize: $data['max_msg_size'] ?? null,
            maxAge: isset($data['max_age']) ? self::nanosToSeconds($data['max_age']) : null,
            storage: StorageType::tryFrom($data['storage'] ?? '') ?? StorageType::File,
            replicas: $data['num_replicas'] ?? 1,
            discard: DiscardPolicy::tryFrom($data['discard'] ?? '') ?? DiscardPolicy::Old,
            noAck: $data['no_ack'] ?? false,
            duplicateWindow: isset($data['duplicate_window']) ? self::nanosToSeconds($data['duplicate_window']) : null,
            allowDirect: $data['allow_direct'] ?? false,
            mirrorDirect: $data['mirror_direct'] ?? false,
            sealed: $data['sealed'] ?? false,
            denyDelete: $data['deny_delete'] ?? false,
            denyPurge: $data['deny_purge'] ?? false,
            allowRollup: $data['allow_rollup_hdrs'] ?? false,
        );
    }

    public static function secondsToNanos(float $seconds): int
    {
        return (int) ($seconds * 1_000_000_000);
    }

    public static function nanosToSeconds(int $nanos): float
    {
        return $nanos / 1_000_000_000;
    }
}
