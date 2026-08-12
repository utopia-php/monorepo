<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Utopia\NATS\Connection as NatsConnection;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\Consumer as NatsConsumer;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\RetentionPolicy;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

/**
 * NATS JetStream broker.
 *
 * Each queue is a WorkQueue-retention stream (a message is removed once acked)
 * with two subjects — normal and priority — served by two durable pull consumers.
 * Redelivery and dead-lettering are native: a rejected message is NAK'd and
 * redelivered until MaxDeliver, after which it is TERM'd and copied to a per-queue
 * dead stream. This replaces the Redis broker's hand-rolled processing/failed/dead
 * lists and its reap()/retry() sweeps (AckWait redelivery reclaims stranded jobs).
 */
class Nats implements Publisher, Consumer
{
    /** @var array<string, bool> queues whose streams/consumers have been provisioned */
    private array $provisioned = [];

    /** @var array<string, array{normal: NatsConsumer, priority: NatsConsumer}> */
    private array $consumers = [];

    /** @var array<string, JetStreamMessage> in-flight messages keyed by pid, for commit/reject */
    private array $inFlight = [];

    private ?NatsConnection $connection = null;
    private ?JetStream $js = null;

    /**
     * A NATS Connection is single-owner (one socket, one shared read pump), so it is
     * NOT safe to share across concurrent coroutines. Pass a Closure factory rather
     * than a live Connection when the consumer forks or reconnects per worker (each
     * worker resolves its own connection), and run at most one message at a time per
     * connection (e.g. Swoole adapter with maxCoroutines: 1) or lease one connection
     * per coroutine from a pool.
     *
     * commit()/reject() correlate the JetStream acknowledgement to a message through an
     * in-instance map keyed by pid, so a message must be committed/rejected on the SAME
     * instance that received it: use one consumer instance (Broker\Pool is for the
     * publisher side).
     *
     * @param NatsConnection|(\Closure(): NatsConnection) $source
     */
    public function __construct(
        private readonly NatsConnection|\Closure $source,
        private readonly float $ackWait = 30.0,
        private readonly int $maxDeliver = 5,
        private readonly int $replicas = 1,
    ) {}

    private function connection(): NatsConnection
    {
        return $this->connection ??= $this->source instanceof \Closure ? ($this->source)() : $this->source;
    }

    private function js(): JetStream
    {
        return $this->js ??= $this->connection()->jetStream();
    }

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        $this->ensure($queue);

        // Match the Redis broker's message shape so Message round-trips identically.
        $message = [
            'pid' => uniqid('', true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];

        $subject = $priority ? $this->prioritySubject($queue) : $this->workSubject($queue);
        $this->js()->publish($subject, (string) json_encode($message));

        return true;
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        $this->ensure($queue);
        $key = $this->identity($queue);

        // Priority first (no_wait poll), then the normal queue for up to $timeout.
        $jsMessage = $this->fetchOne($this->consumers[$key]['priority'], 0.25, true)
            ?? $this->fetchOne($this->consumers[$key]['normal'], (float) $timeout, false);

        if (!$jsMessage instanceof JetStreamMessage) {
            return null;
        }

        /** @var array{pid: string, queue: string, timestamp: int, payload: array<mixed>} $data */
        $data = json_decode($jsMessage->getData(), true);
        $this->inFlight[$data['pid']] = $jsMessage;

        return new Message($data)
            // JetStream counts deliveries from 1; expose it as the Redis-style attempt count.
            ->setAttempts(max(0, $jsMessage->metadata()->numDelivered - 1));
    }

    public function commit(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();
        $jsMessage = $this->inFlight[$pid] ?? null;
        if ($jsMessage instanceof JetStreamMessage) {
            $jsMessage->ackSync();
            unset($this->inFlight[$pid]);
        }
    }

    public function reject(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();
        $jsMessage = $this->inFlight[$pid] ?? null;
        if (!$jsMessage instanceof JetStreamMessage) {
            return;
        }
        unset($this->inFlight[$pid]);

        if ($jsMessage->metadata()->numDelivered >= $this->maxDeliver) {
            // Exhausted: park on the dead stream and drop it from the work stream.
            $this->js()->publish($this->deadSubject($queue), $jsMessage->getData());
            $jsMessage->term('max deliveries exceeded');

            return;
        }

        // Redeliver later (AckWait/NAK); a crashed worker is reclaimed the same way.
        $jsMessage->nak();
    }

    /**
     * Re-drive dead-lettered messages back onto the work queue, up to $limit.
     *
     * $maxAttempts and $newerThan exist only for signature compatibility with
     * Broker\Redis::retry() (cloud calls it with them); they are not applied here.
     * In the JetStream model attempts are capped server-side by maxDeliver before a
     * message reaches the dead stream, so there is nothing left to gate on re-drive.
     */
    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $this->ensure($queue);

        $consumer = $this->js()->createConsumer($this->deadStream($queue), new ConsumerConfig(
            durableName: 'retry',
            ackPolicy: AckPolicy::Explicit,
            ackWait: $this->ackWait,
            filterSubject: $this->deadSubject($queue),
        ));

        $remaining = $limit ?? 500;
        while ($remaining > 0) {
            $jsMessage = $this->fetchOne($consumer, 1.0, false);
            if (!$jsMessage instanceof JetStreamMessage) {
                break;
            }
            // Re-drive onto the work queue, then remove it from the dead stream.
            $this->js()->publish($this->workSubject($queue), $jsMessage->getData());
            $jsMessage->ackSync();
            $remaining--;
        }
    }

    /**
     * Reaping stranded in-flight jobs is unnecessary on JetStream: AckWait redelivery
     * reclaims a message whose worker died before committing. Kept for drop-in
     * compatibility with the Redis broker's call sites; always returns 0.
     */
    public function reap(Queue $queue, int $olderThan = 90000, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): int
    {
        return 0;
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $this->ensure($queue);

        if ($failedJobs) {
            return $this->js()->getStreamInfo($this->deadStream($queue))->state->messages;
        }

        $key = $this->identity($queue);

        return $this->consumers[$key]['normal']->info(true)->numPending
            + $this->consumers[$key]['priority']->info(true)->numPending;
    }

    public function close(): void
    {
        if ($this->connection instanceof NatsConnection) {
            $this->connection->close();
        }
    }

    /** Fetch a single message, or null on timeout / empty. */
    private function fetchOne(NatsConsumer $consumer, float $timeout, bool $noWait): ?JetStreamMessage
    {
        foreach ($consumer->fetch(1, $timeout, $noWait) as $message) {
            return $message;
        }

        return null;
    }

    /** Idempotently provision the work + dead streams and the durable consumers. */
    private function ensure(Queue $queue): void
    {
        $key = $this->identity($queue);
        if (isset($this->provisioned[$key])) {
            return;
        }

        $maxAge = $queue->jobTtl > 0 ? (float) $queue->jobTtl : null;

        $this->js()->createOrUpdateStream(new StreamConfig(
            name: $this->workStream($queue),
            subjects: [$this->workSubject($queue), $this->prioritySubject($queue)],
            retention: RetentionPolicy::WorkQueue,
            maxAge: $maxAge,
            storage: StorageType::File,
            replicas: $this->replicas,
        ));

        $this->js()->createOrUpdateStream(new StreamConfig(
            name: $this->deadStream($queue),
            subjects: [$this->deadSubject($queue)],
            retention: RetentionPolicy::WorkQueue,
            storage: StorageType::File,
            replicas: $this->replicas,
        ));

        $this->consumers[$key] = [
            'normal' => $this->js()->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: 'worker',
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->workSubject($queue),
            )),
            'priority' => $this->js()->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: 'worker_priority',
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->prioritySubject($queue),
            )),
        ];

        $this->provisioned[$key] = true;
    }

    /** Logical queue identity (namespace + name); used for cache keys and stream naming. */
    private function identity(Queue $queue): string
    {
        return $queue->namespace . '.' . $queue->name;
    }

    private function workStream(Queue $queue): string
    {
        // A readable sanitized prefix plus a hash of the full identity: the hash keeps
        // distinct queues from colliding once sanitize() replaces characters — e.g.
        // "a.b" vs "a_b", or the same name across two namespaces.
        return 'QUEUE_' . $this->sanitize("{$queue->namespace}_{$queue->name}") . '_' . substr(sha1($this->identity($queue)), 0, 10);
    }

    private function deadStream(Queue $queue): string
    {
        return $this->workStream($queue) . '_DEAD';
    }

    private function workSubject(Queue $queue): string
    {
        return "{$queue->namespace}.queue.{$queue->name}";
    }

    private function prioritySubject(Queue $queue): string
    {
        // A distinct leading segment (like the dead subject) rather than a ".priority"
        // suffix, so the queue name stays the tail: otherwise queue "b"'s priority
        // subject would equal queue "b.priority"'s normal subject.
        return "{$queue->namespace}.priority.{$queue->name}";
    }

    private function deadSubject(Queue $queue): string
    {
        return "{$queue->namespace}.dead.{$queue->name}";
    }

    /** Stream names allow only A-Z a-z 0-9 _ - (no dots), unlike subject/queue names. */
    private function sanitize(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    }
}
