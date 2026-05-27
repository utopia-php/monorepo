<?php

declare(strict_types=1);

namespace Nats\JetStream;

final class ConsumerConfig
{
    /**
     * @param float|null $ackWait Ack wait in seconds
     * @param float|null $inactiveThreshold Inactive threshold in seconds
     * @param list<string>|null $filterSubjects
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $durableName = null,
        public readonly ?string $description = null,
        public readonly DeliverPolicy $deliverPolicy = DeliverPolicy::All,
        public readonly AckPolicy $ackPolicy = AckPolicy::Explicit,
        public readonly ?float $ackWait = null,
        public readonly ?int $maxDeliver = null,
        public readonly ?string $filterSubject = null,
        public readonly ?array $filterSubjects = null,
        public readonly ReplayPolicy $replayPolicy = ReplayPolicy::Instant,
        public readonly ?int $maxWaiting = null,
        public readonly ?int $maxAckPending = null,
        public readonly ?float $inactiveThreshold = null,
        public readonly ?int $optStartSeq = null,
        public readonly ?string $optStartTime = null,
        public readonly ?int $maxBatch = null,
        public readonly ?int $maxBytes = null,
        public readonly bool $memStorage = false,
        public readonly ?int $numReplicas = null,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'deliver_policy' => $this->deliverPolicy->value,
            'ack_policy' => $this->ackPolicy->value,
            'replay_policy' => $this->replayPolicy->value,
        ];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->durableName !== null) {
            $data['durable_name'] = $this->durableName;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->ackWait !== null) {
            $data['ack_wait'] = StreamConfig::secondsToNanos($this->ackWait);
        }
        if ($this->maxDeliver !== null) {
            $data['max_deliver'] = $this->maxDeliver;
        }
        if ($this->filterSubject !== null) {
            $data['filter_subject'] = $this->filterSubject;
        }
        if ($this->filterSubjects !== null) {
            $data['filter_subjects'] = $this->filterSubjects;
        }
        if ($this->maxWaiting !== null) {
            $data['max_waiting'] = $this->maxWaiting;
        }
        if ($this->maxAckPending !== null) {
            $data['max_ack_pending'] = $this->maxAckPending;
        }
        if ($this->inactiveThreshold !== null) {
            $data['inactive_threshold'] = StreamConfig::secondsToNanos($this->inactiveThreshold);
        }
        if ($this->optStartSeq !== null) {
            $data['opt_start_seq'] = $this->optStartSeq;
        }
        if ($this->optStartTime !== null) {
            $data['opt_start_time'] = $this->optStartTime;
        }
        if ($this->maxBatch !== null) {
            $data['max_batch'] = $this->maxBatch;
        }
        if ($this->maxBytes !== null) {
            $data['max_bytes'] = $this->maxBytes;
        }
        if ($this->memStorage) {
            $data['mem_storage'] = true;
        }
        if ($this->numReplicas !== null) {
            $data['num_replicas'] = $this->numReplicas;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            durableName: $data['durable_name'] ?? null,
            description: $data['description'] ?? null,
            deliverPolicy: DeliverPolicy::tryFrom($data['deliver_policy'] ?? '') ?? DeliverPolicy::All,
            ackPolicy: AckPolicy::tryFrom($data['ack_policy'] ?? '') ?? AckPolicy::Explicit,
            ackWait: isset($data['ack_wait']) ? StreamConfig::nanosToSeconds($data['ack_wait']) : null,
            maxDeliver: $data['max_deliver'] ?? null,
            filterSubject: $data['filter_subject'] ?? null,
            filterSubjects: $data['filter_subjects'] ?? null,
            replayPolicy: ReplayPolicy::tryFrom($data['replay_policy'] ?? '') ?? ReplayPolicy::Instant,
            maxWaiting: $data['max_waiting'] ?? null,
            maxAckPending: $data['max_ack_pending'] ?? null,
            inactiveThreshold: isset($data['inactive_threshold']) ? StreamConfig::nanosToSeconds($data['inactive_threshold']) : null,
            optStartSeq: $data['opt_start_seq'] ?? null,
            optStartTime: $data['opt_start_time'] ?? null,
            maxBatch: $data['max_batch'] ?? null,
            maxBytes: $data['max_bytes'] ?? null,
            memStorage: $data['mem_storage'] ?? false,
            numReplicas: $data['num_replicas'] ?? null,
        );
    }
}
