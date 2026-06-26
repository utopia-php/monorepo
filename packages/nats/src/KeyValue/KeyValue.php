<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\KeyValueException;
use Utopia\NATS\Headers;
use Utopia\NATS\JetStream\JetStream;

final class KeyValue
{
    public function __construct(
        private readonly Connection $conn,
        private readonly JetStream $js,
        private readonly string $bucket,
    ) {}

    public function get(string $key): KeyValueEntry
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";

        try {
            $msg = $this->conn->request("\$JS.API.DIRECT.GET.KV_{$this->bucket}", json_encode([
                'last_by_subj' => $subject,
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new KeyValueException("Key not found: {$key}");
        }

        // Check for delete/purge markers
        if ($msg->headers instanceof \Utopia\NATS\Headers) {
            $op = $msg->headers->get('KV-Operation');
            if ($op === 'DEL' || $op === 'PURGE') {
                throw new KeyValueException("Key not found: {$key}");
            }
        }

        $revision = 0;
        $created = null;
        if ($msg->headers instanceof \Utopia\NATS\Headers) {
            $seqStr = $msg->headers->get('Nats-Sequence');
            if ($seqStr !== null) {
                $revision = (int) $seqStr;
            }
            $created = $msg->headers->get('Nats-Time-Stamp');
        }

        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: $msg->data,
            revision: $revision,
            created: $created,
            operation: KeyValueOperation::Put,
        );
    }

    /**
     * Put a value, returning the revision number.
     */
    public function put(string $key, string $value): int
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $ack = $this->js->publish($subject, $value);

        return $ack->sequence;
    }

    /**
     * Create a key only if it does not already exist.
     */
    public function create(string $key, string $value): int
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $headers = new Headers();
        $headers->set('Nats-Expected-Last-Subject-Sequence', '0');

        try {
            $ack = $this->js->publish($subject, $value, $headers);
        } catch (\Throwable $e) {
            throw new KeyValueException("Key already exists: {$key}", $e->getCode(), previous: $e);
        }

        return $ack->sequence;
    }

    /**
     * Update a key only if the current revision matches (CAS).
     */
    public function update(string $key, string $value, int $revision): int
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";

        try {
            $ack = $this->js->publish(
                $subject,
                $value,
                expectedLastSubjectSeq: $revision,
            );
        } catch (\Throwable $e) {
            throw new KeyValueException("Wrong last revision for key: {$key}", $e->getCode(), previous: $e);
        }

        return $ack->sequence;
    }

    public function delete(string $key): void
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $headers = new Headers();
        $headers->set('KV-Operation', 'DEL');

        $this->js->publish($subject, '', $headers);
    }

    public function purge(string $key): void
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $headers = new Headers();
        $headers->set('KV-Operation', 'PURGE');
        $headers->set('Nats-Rollup', 'sub');

        $this->js->publish($subject, '', $headers);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $streamName = "KV_{$this->bucket}";
        $subject = "\$KV.{$this->bucket}.>";

        // Use stream subjects to get all keys
        try {
            $msg = $this->conn->request('$JS.API.STREAM.INFO.' . $streamName, json_encode([
                'subjects_filter' => $subject,
            ], JSON_THROW_ON_ERROR));

            $data = json_decode($msg->data, true, 512, JSON_THROW_ON_ERROR);
            JetStream::checkError($data);

            $keys = [];
            $prefix = "\$KV.{$this->bucket}.";
            $subjects = $data['state']['subjects'] ?? [];
            foreach ($subjects as $subj => $count) {
                if (str_starts_with((string) $subj, $prefix)) {
                    $keys[] = substr((string) $subj, \strlen($prefix));
                }
            }

            return $keys;
        } catch (\Throwable) {
            return [];
        }
    }

    public function status(): KeyValueStatus
    {
        $info = $this->js->getStreamInfo("KV_{$this->bucket}");

        return new KeyValueStatus(
            bucket: $this->bucket,
            values: $info->state->messages,
            bytes: $info->state->bytes,
            history: $info->config->maxMsgsPerSubject,
            ttl: $info->config->maxAge,
            streamInfo: $info,
        );
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || str_contains($key, ' ') || str_contains($key, '>') || str_contains($key, '*')) {
            throw new KeyValueException("Invalid key: {$key}");
        }
    }
}
