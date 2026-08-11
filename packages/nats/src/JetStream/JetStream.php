<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\Headers;
use Utopia\NATS\KeyValue\KeyValue;
use Utopia\NATS\KeyValue\KeyValueConfig;

final class JetStream
{
    private readonly string $apiPrefix;

    public function __construct(
        private readonly Connection $conn,
        ?string $domain = null,
        ?string $apiPrefix = null,
    ) {
        if ($apiPrefix !== null) {
            $this->apiPrefix = $apiPrefix;
        } elseif ($domain !== null) {
            $this->apiPrefix = "\$JS.{$domain}.API";
        } else {
            $this->apiPrefix = '$JS.API';
        }
    }

    // --- Stream Management ---

    public function createStream(StreamConfig $config): Stream
    {
        $data = $this->apiRequest("STREAM.CREATE.{$config->name}", $config->toArray());
        return new Stream($this, StreamInfo::fromArray($data));
    }

    public function updateStream(StreamConfig $config): Stream
    {
        $data = $this->apiRequest("STREAM.UPDATE.{$config->name}", $config->toArray());
        return new Stream($this, StreamInfo::fromArray($data));
    }

    public function createOrUpdateStream(StreamConfig $config): Stream
    {
        try {
            return $this->updateStream($config);
        } catch (JetStreamException $e) {
            if ($e->apiError instanceof \Utopia\NATS\JetStream\ApiError && $e->apiError->code === 404) {
                return $this->createStream($config);
            }
            throw $e;
        }
    }

    public function deleteStream(string $name): void
    {
        $this->apiRequest("STREAM.DELETE.{$name}");
    }

    public function getStream(string $name): Stream
    {
        $info = $this->getStreamInfo($name);
        return new Stream($this, $info);
    }

    public function getStreamInfo(string $name): StreamInfo
    {
        $data = $this->apiRequest("STREAM.INFO.{$name}");
        return StreamInfo::fromArray($data);
    }

    /** @return list<string> */
    public function getStreamNames(?string $subject = null): array
    {
        $payload = $subject !== null ? ['subject' => $subject] : null;
        $data = $this->apiRequest('STREAM.NAMES', $payload);
        return $data['streams'] ?? [];
    }

    /** @return list<StreamInfo> */
    public function listStreams(?string $subject = null): array
    {
        $payload = $subject !== null ? ['subject' => $subject] : null;
        $data = $this->apiRequest('STREAM.LIST', $payload);
        return array_map(
            StreamInfo::fromArray(...),
            $data['streams'] ?? [],
        );
    }

    public function purgeStream(string $name, ?string $subject = null): void
    {
        $payload = $subject !== null ? ['filter' => $subject] : null;
        $this->apiRequest("STREAM.PURGE.{$name}", $payload);
    }

    // --- Consumer Management ---

    public function createConsumer(string $stream, ConsumerConfig $config): Consumer
    {
        $consumerName = $config->name ?? $config->durableName;

        $subject = $consumerName !== null ? "CONSUMER.CREATE.{$stream}.{$consumerName}" : "CONSUMER.CREATE.{$stream}";

        $payload = [
            'stream_name' => $stream,
            'config' => $config->toArray(),
        ];

        $data = $this->apiRequest($subject, $payload);
        return new Consumer($this->conn, $stream, ConsumerInfo::fromArray($data), $this->apiPrefix);
    }

    public function updateConsumer(string $stream, ConsumerConfig $config): Consumer
    {
        return $this->createConsumer($stream, $config);
    }

    public function deleteConsumer(string $stream, string $consumer): void
    {
        $this->apiRequest("CONSUMER.DELETE.{$stream}.{$consumer}");
    }

    public function getConsumer(string $stream, string $consumer): Consumer
    {
        $data = $this->apiRequest("CONSUMER.INFO.{$stream}.{$consumer}");
        return new Consumer($this->conn, $stream, ConsumerInfo::fromArray($data), $this->apiPrefix);
    }

    /** @return list<string> */
    public function getConsumerNames(string $stream): array
    {
        $data = $this->apiRequest("CONSUMER.NAMES.{$stream}");
        return $data['consumers'] ?? [];
    }

    /**
     * List consumers of a stream as typed ConsumerInfo objects, following the
     * API's offset/total paging until every consumer has been retrieved.
     *
     * @return list<ConsumerInfo>
     */
    public function getConsumers(string $stream): array
    {
        $consumers = [];
        $offset = 0;

        do {
            $data = $this->apiRequest("CONSUMER.LIST.{$stream}", ['offset' => $offset]);
            $page = $data['consumers'] ?? [];
            foreach ($page as $entry) {
                $consumers[] = ConsumerInfo::fromArray($entry);
            }
            $total = $data['total'] ?? \count($consumers);
            $offset = \count($consumers);
        } while ($page !== [] && $offset < $total);

        return $consumers;
    }

    /**
     * Create a push consumer and deliver its messages to the callback.
     *
     * If the config has no deliver subject, an inbox is generated. The callback
     * receives a JetStreamMessage and may ack it. Drive delivery with
     * Connection::wait().
     */
    public function pushSubscribe(string $stream, ConsumerConfig $config, \Closure $callback): PushSubscription
    {
        if ($config->deliverSubject === null) {
            $config = ConsumerConfig::fromArray(
                ['deliver_subject' => $this->conn->newInbox()] + $config->toArray(),
            );
        }

        $consumer = $this->createConsumer($stream, $config);

        return new PushSubscription($this->conn, $consumer->info(), $callback);
    }

    /**
     * Create an ordered (ephemeral, in-order, auto-healing) push consumer.
     */
    public function orderedConsumer(string $stream, DeliverPolicy $deliverPolicy = DeliverPolicy::All, ?string $filterSubject = null, float $idleHeartbeat = 5.0): OrderedConsumer
    {
        return new OrderedConsumer($this->conn, $this, $stream, $deliverPolicy, $filterSubject, $idleHeartbeat);
    }

    // --- Stream Message Operations ---

    public function getMessage(string $stream, int $seq): StreamMessage
    {
        $data = $this->apiRequest("STREAM.MSG.GET.{$stream}", ['seq' => $seq]);
        return StreamMessage::fromArray($data['message'] ?? []);
    }

    public function getLastMessage(string $stream, string $subject): StreamMessage
    {
        $data = $this->apiRequest("STREAM.MSG.GET.{$stream}", ['last_by_subj' => $subject]);
        return StreamMessage::fromArray($data['message'] ?? []);
    }

    public function deleteMessage(string $stream, int $seq, bool $noErase = false): void
    {
        $payload = ['seq' => $seq];
        if ($noErase) {
            $payload['no_erase'] = true;
        }
        $this->apiRequest("STREAM.MSG.DELETE.{$stream}", $payload);
    }

    // --- Publishing ---

    /**
     * @param int|string|null $ttl Per-message TTL (ADR-43). An int is interpreted as
     *                             seconds; a string is passed through as a duration
     *                             (e.g. "1s", "never"). Sets the Nats-TTL header.
     * @param int $retryOnNoResponders Number of extra attempts if the publish is met
     *                                 with no responders / 503 (ADR-22). 0 keeps the
     *                                 default single-attempt behavior.
     */
    public function publish(string $subject, string $data = '', ?Headers $headers = null, ?string $msgId = null, ?string $expectedLastMsgId = null, ?int $expectedLastSeq = null, ?int $expectedLastSubjectSeq = null, ?string $expectedStream = null, int|string|null $ttl = null, int $retryOnNoResponders = 0): PubAck
    {
        $headers ??= new Headers();

        if ($msgId !== null) {
            $headers->set('Nats-Msg-Id', $msgId);
        }
        if ($expectedLastMsgId !== null) {
            $headers->set('Nats-Expected-Last-Msg-Id', $expectedLastMsgId);
        }
        if ($expectedLastSeq !== null) {
            $headers->set('Nats-Expected-Last-Sequence', (string) $expectedLastSeq);
        }
        if ($expectedLastSubjectSeq !== null) {
            $headers->set('Nats-Expected-Last-Subject-Sequence', (string) $expectedLastSubjectSeq);
        }
        if ($expectedStream !== null) {
            $headers->set('Nats-Expected-Stream', $expectedStream);
        }
        if ($ttl !== null) {
            $headers->set('Nats-TTL', \is_int($ttl) ? "{$ttl}s" : $ttl);
        }

        $useHeaders = \count($headers) > 0 ? $headers : null;

        $attempt = 0;
        while (true) {
            try {
                $response = $this->conn->request($subject, $data, headers: $useHeaders);
                break;
            } catch (\Utopia\NATS\Exception\NatsException $e) {
                if ($attempt >= $retryOnNoResponders || $e->getMessage() !== 'No responders for request') {
                    throw $e;
                }
                $attempt++;
                usleep(50_000 * $attempt);
            }
        }

        $responseData = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        self::checkError($responseData);

        return PubAck::fromArray($responseData);
    }

    // --- Key-Value ---

    public function createKeyValue(KeyValueConfig $config): KeyValue
    {
        $streamConfig = $config->toStreamConfig();
        $this->createOrUpdateStream($streamConfig);
        return new KeyValue($this->conn, $this, $config->bucket);
    }

    public function getKeyValue(string $bucket): KeyValue
    {
        // Verify the KV stream exists
        $this->getStreamInfo("KV_{$bucket}");
        return new KeyValue($this->conn, $this, $bucket);
    }

    public function deleteKeyValue(string $bucket): void
    {
        $this->deleteStream("KV_{$bucket}");
    }

    // --- Object Store ---

    public function getObjectStore(string $bucket): \Utopia\NATS\ObjectStore\ObjectStore
    {
        // Verify the object-store stream exists.
        $this->getStreamInfo("OBJ_{$bucket}");
        return new \Utopia\NATS\ObjectStore\ObjectStore($this->conn, $this, $bucket);
    }

    public function deleteObjectStore(string $bucket): void
    {
        $this->deleteStream("OBJ_{$bucket}");
    }

    // --- Account Info ---

    public function accountInfo(): AccountInfo
    {
        return AccountInfo::fromArray($this->apiRequest('INFO'));
    }

    // --- Internal ---

    /**
     * @return array<string, mixed>
     */
    private function apiRequest(string $subject, ?array $payload = null, ?float $timeout = null): array
    {
        $fullSubject = "{$this->apiPrefix}.{$subject}";
        $body = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : '';

        $response = $this->conn->request($fullSubject, $body, $timeout);
        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);

        self::checkError($data);

        return $data;
    }

    /**
     * @throws JetStreamException
     */
    public static function checkError(array $data): void
    {
        if (isset($data['error'])) {
            $error = ApiError::fromArray($data['error']);
            throw new JetStreamException(
                $error->description,
                $error->code,
                apiError: $error,
            );
        }
    }
}
