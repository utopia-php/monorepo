<?php

declare(strict_types=1);

namespace Utopia\NATS\ObjectStore;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\ObjectStoreException;
use Utopia\NATS\Headers;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\DeliverPolicy;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\StreamInfo;

final class ObjectStore
{
    private const CHUNK_SIZE = 128 * 1024;

    public function __construct(
        private readonly Connection $conn,
        private readonly JetStream $js,
        private readonly string $bucket,
    ) {}

    /**
     * Create (or update) the backing stream and return a store handle.
     */
    public static function createOrUpdate(Connection $conn, JetStream $js, ObjectStoreConfig $config): self
    {
        $js->createOrUpdateStream($config->toStreamConfig());

        return new self($conn, $js, $config->bucket);
    }

    /**
     * Store an object, chunking its data and writing a meta record.
     */
    public function put(string $name, string $data): ObjectMeta
    {
        [$previous, $previousSeq] = $this->readMetaWithSeq($name);

        return $this->writeVersion($name, $data, $previous, $previousSeq);
    }

    /**
     * Write a new version of an object, expecting the meta subject to still be at
     * $expectedSeq (0 = must not exist yet). Separated from put() so the optimistic
     * concurrency path can be driven deterministically in tests.
     */
    private function writeVersion(string $name, string $data, ?ObjectMeta $previous, int $expectedSeq): ObjectMeta
    {
        $nuid = strtoupper(bin2hex(random_bytes(12)));
        $chunkSubject = "\$O.{$this->bucket}.C.{$nuid}";

        $chunks = 0;
        $length = \strlen($data);
        for ($offset = 0; $offset < $length; $offset += self::CHUNK_SIZE) {
            $this->js->publish($chunkSubject, substr($data, $offset, self::CHUNK_SIZE));
            $chunks++;
        }

        $meta = new ObjectMeta(
            name: $name,
            bucket: $this->bucket,
            nuid: $nuid,
            size: $length,
            chunks: $chunks,
            digest: $this->digest($data),
            modified: gmdate('Y-m-d\TH:i:s\Z'),
        );

        // Optimistic concurrency: the meta publish only succeeds if the meta subject's
        // last sequence still matches what we read (0 means "must not exist yet"). If a
        // concurrent or stale writer already advanced it, JetStream rejects the publish,
        // we purge only the chunks THIS put wrote (never the previous NUID), and surface a
        // clear conflict. This replaces the former last-writer-wins behaviour that could
        // orphan the loser's chunks.
        $headers = new Headers();
        $headers->set('Nats-Rollup', 'sub');
        try {
            $this->js->publish(
                $this->metaSubject($name),
                json_encode($meta->toArray(), JSON_THROW_ON_ERROR),
                $headers,
                expectedLastSubjectSeq: $expectedSeq,
            );
        } catch (\Throwable $e) {
            $this->purgeChunks($nuid);
            throw new ObjectStoreException("conflicting concurrent write for object: {$name}", $e->getCode(), previous: $e);
        }

        // Reclaim chunks left behind by a prior version of this object.
        if ($previous instanceof ObjectMeta && $previous->nuid !== '' && $previous->nuid !== $nuid) {
            $this->purgeChunks($previous->nuid);
        }

        return $meta;
    }

    /**
     * Retrieve an object's bytes, reassembling and verifying its chunks.
     */
    public function get(string $name): string
    {
        $meta = $this->readMeta($name);
        if (!$meta instanceof ObjectMeta || $meta->deleted) {
            throw new \RuntimeException("Object not found: {$name}");
        }

        $data = '';
        if ($meta->chunks > 0) {
            $stream = "OBJ_{$this->bucket}";
            $subject = "\$O.{$this->bucket}.C.{$meta->nuid}";

            $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
                deliverPolicy: DeliverPolicy::All,
                ackPolicy: AckPolicy::Explicit,
                filterSubject: $subject,
                inactiveThreshold: 30.0,
            ));

            try {
                foreach ($consumer->fetch($meta->chunks, 10.0) as $msg) {
                    $data .= $msg->getData();
                    $msg->ack();
                }
            } finally {
                try {
                    $this->js->deleteConsumer($stream, $consumer->getName());
                } catch (\Throwable) {
                    // Ephemeral consumer expires on its own.
                }
            }
        }

        if (\strlen($data) !== $meta->size || $this->digest($data) !== $meta->digest) {
            throw new \RuntimeException("Object integrity check failed: {$name}");
        }

        return $data;
    }

    public function getMeta(string $name): ObjectMeta
    {
        $meta = $this->readMeta($name);
        if (!$meta instanceof ObjectMeta || $meta->deleted) {
            throw new \RuntimeException("Object not found: {$name}");
        }

        return $meta;
    }

    public function delete(string $name): void
    {
        $meta = $this->readMeta($name);
        if (!$meta instanceof ObjectMeta) {
            return;
        }

        if ($meta->nuid !== '') {
            $this->purgeChunks($meta->nuid);
        }

        $this->js->purgeStream("OBJ_{$this->bucket}", $this->metaSubject($name));
    }

    /**
     * List all (non-deleted) objects in the bucket.
     *
     * @return list<ObjectMeta>
     */
    public function list(): array
    {
        $stream = "OBJ_{$this->bucket}";
        $subject = "\$O.{$this->bucket}.M.>";

        try {
            $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
                deliverPolicy: DeliverPolicy::LastPerSubject,
                ackPolicy: AckPolicy::Explicit,
                filterSubject: $subject,
                inactiveThreshold: 30.0,
            ));
        } catch (\Throwable) {
            return [];
        }

        try {
            $objects = [];
            foreach ($consumer->fetch(1024, 1.0) as $msg) {
                $msg->ack();
                $decoded = json_decode((string) $msg->getData(), true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($decoded)) {
                    continue;
                }
                $meta = ObjectMeta::fromArray($decoded);
                if (!$meta->deleted) {
                    $objects[] = $meta;
                }
            }

            return $objects;
        } finally {
            try {
                $this->js->deleteConsumer($stream, $consumer->getName());
            } catch (\Throwable) {
                // Ephemeral consumer expires on its own.
            }
        }
    }

    public function status(): StreamInfo
    {
        return $this->js->getStreamInfo("OBJ_{$this->bucket}");
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    private function readMeta(string $name): ?ObjectMeta
    {
        return $this->readMetaWithSeq($name)[0];
    }

    /**
     * Read the latest meta record for an object along with its stream sequence.
     * The sequence is 0 when no meta record exists, which callers use as the
     * expected-last-subject-sequence for an optimistic first write.
     *
     * @return array{0: ?ObjectMeta, 1: int}
     */
    private function readMetaWithSeq(string $name): array
    {
        try {
            $response = $this->conn->request(
                "\$JS.API.STREAM.MSG.GET.OBJ_{$this->bucket}",
                json_encode(['last_by_subj' => $this->metaSubject($name)], JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            return [null, 0];
        }

        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        if (isset($data['error']) || !isset($data['message']['data'])) {
            return [null, 0];
        }

        $decoded = json_decode((string) base64_decode((string) $data['message']['data'], true), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [null, 0];
        }

        return [ObjectMeta::fromArray($decoded), (int) ($data['message']['seq'] ?? 0)];
    }

    private function purgeChunks(string $nuid): void
    {
        try {
            $this->js->purgeStream("OBJ_{$this->bucket}", "\$O.{$this->bucket}.C.{$nuid}");
        } catch (\Throwable) {
            // Best effort cleanup.
        }
    }

    private function metaSubject(string $name): string
    {
        $token = rtrim(strtr(base64_encode($name), '+/', '-_'), '=');

        return "\$O.{$this->bucket}.M.{$token}";
    }

    private function digest(string $data): string
    {
        $raw = hash('sha256', $data, true);

        return 'SHA-256=' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
