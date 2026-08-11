<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\KeyValueException;
use Utopia\NATS\Headers;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\DeliverPolicy;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\MsgMetadata;
use Utopia\NATS\Message;
use Utopia\NATS\Subscription;

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

    /**
     * Fetch a specific revision of a key by its stream sequence.
     */
    public function getRevision(string $key, int $seq): KeyValueEntry
    {
        $this->validateKey($key);

        return $this->fetchStored(['seq' => $seq], $key);
    }

    /**
     * Return every stored revision for a key, oldest first.
     *
     * @return list<KeyValueEntry>
     */
    public function history(string $key): array
    {
        $this->validateKey($key);

        $stream = "KV_{$this->bucket}";
        $subject = "\$KV.{$this->bucket}.{$key}";

        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            deliverPolicy: DeliverPolicy::All,
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
            inactiveThreshold: 30.0,
        ));

        try {
            $entries = [];
            foreach ($consumer->fetch(1024, 1.0) as $msg) {
                $entries[] = $this->entryFromDelivered($key, $msg);
                $msg->ack();
            }

            return $entries;
        } finally {
            try {
                $this->js->deleteConsumer($stream, $consumer->getName());
            } catch (\Throwable) {
                // Ephemeral consumer will expire on its own.
            }
        }
    }

    /**
     * Watch a key (or wildcard pattern) for live put/delete updates.
     *
     * The returned subscription must be pumped by the connection
     * (e.g. `$conn->wait()`), and unsubscribed when no longer needed.
     *
     * @param callable(KeyValueEntry): void $callback
     */
    public function watch(string $keyPattern, callable $callback): Subscription
    {
        $stream = "KV_{$this->bucket}";
        $filter = "\$KV.{$this->bucket}.{$keyPattern}";
        $deliverSubject = $this->conn->newInbox();

        $payload = json_encode([
            'stream_name' => $stream,
            'config' => [
                'deliver_subject' => $deliverSubject,
                'deliver_policy' => 'new',
                'ack_policy' => 'none',
                'filter_subject' => $filter,
                'inactive_threshold' => (int) (30 * 1_000_000_000),
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->conn->request("\$JS.API.CONSUMER.CREATE.{$stream}", $payload);
        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($data);

        return $this->conn->subscribe($deliverSubject, function (Message $msg) use ($callback): void {
            // Ignore JetStream idle heartbeats / flow control (100 status).
            if ($msg->headers instanceof Headers && $msg->headers->getStatus() !== '') {
                return;
            }
            $callback($this->entryFromMessage($msg));
        });
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    private function entryFromDelivered(string $key, JetStreamMessage $msg): KeyValueEntry
    {
        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: $msg->getData(),
            revision: $msg->metadata()->streamSequence,
            created: $msg->metadata()->timestamp,
            operation: $this->operationFromHeaders($msg->getHeaders()),
        );
    }

    private function entryFromMessage(Message $msg): KeyValueEntry
    {
        $prefix = "\$KV.{$this->bucket}.";
        $key = str_starts_with($msg->subject, $prefix)
            ? substr($msg->subject, \strlen($prefix))
            : $msg->subject;

        $revision = 0;
        $created = null;
        if ($msg->replyTo !== null) {
            $meta = MsgMetadata::fromReplySubject($msg->replyTo);
            $revision = $meta->streamSequence;
            $created = $meta->timestamp;
        }

        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: $msg->data,
            revision: $revision,
            created: $created,
            operation: $this->operationFromHeaders($msg->headers),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function fetchStored(array $request, string $key): KeyValueEntry
    {
        try {
            $response = $this->conn->request(
                "\$JS.API.STREAM.MSG.GET.KV_{$this->bucket}",
                json_encode($request, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            throw new KeyValueException("Revision not found for key: {$key}", previous: $e);
        }

        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($data);

        $stored = $data['message'] ?? null;
        if (!\is_array($stored)) {
            throw new KeyValueException("Revision not found for key: {$key}");
        }

        // A sequence lookup is stream-global: reject a revision that belongs to a different key.
        $expectedSubject = "\$KV.{$this->bucket}.{$key}";
        if (isset($stored['subject']) && $stored['subject'] !== $expectedSubject) {
            throw new KeyValueException("Revision not found for key: {$key}");
        }

        $headers = null;
        if (isset($stored['hdrs']) && \is_string($stored['hdrs'])) {
            $headers = Headers::fromWire((string) base64_decode($stored['hdrs'], true));
        }

        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: isset($stored['data']) ? (string) base64_decode((string) $stored['data'], true) : '',
            revision: (int) ($stored['seq'] ?? 0),
            created: $stored['time'] ?? null,
            operation: $this->operationFromHeaders($headers),
        );
    }

    private function operationFromHeaders(?Headers $headers): KeyValueOperation
    {
        if (!$headers instanceof Headers) {
            return KeyValueOperation::Put;
        }

        return match ($headers->get('KV-Operation')) {
            'DEL' => KeyValueOperation::Delete,
            'PURGE' => KeyValueOperation::Purge,
            default => KeyValueOperation::Put,
        };
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || str_contains($key, ' ') || str_contains($key, '>') || str_contains($key, '*')) {
            throw new KeyValueException("Invalid key: {$key}");
        }
    }
}
