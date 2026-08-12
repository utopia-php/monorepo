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

    private readonly JetStream $js;

    public function __construct(
        private readonly NatsConnection $connection,
        private readonly float $ackWait = 30.0,
        private readonly int $maxDeliver = 5,
        private readonly int $replicas = 1,
    ) {
        $this->js = $connection->jetStream();
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
        $this->js->publish($subject, (string) json_encode($message));

        return true;
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        $this->ensure($queue);

        // Priority first (no_wait poll), then the normal queue for up to $timeout.
        $jsMessage = $this->fetchOne($this->consumers[$queue->name]['priority'], 0.25, true)
            ?? $this->fetchOne($this->consumers[$queue->name]['normal'], (float) $timeout, false);

        if (!$jsMessage instanceof JetStreamMessage) {
            return null;
        }

        /** @var array{pid: string, queue: string, timestamp: int, payload: array<mixed>} $data */
        $data = json_decode($jsMessage->getData(), true);
        $this->inFlight[$data['pid']] = $jsMessage;

        return (new Message($data))
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
            $this->js->publish($this->deadSubject($queue), $jsMessage->getData());
            $jsMessage->term('max deliveries exceeded');

            return;
        }

        // Redeliver later (AckWait/NAK); a crashed worker is reclaimed the same way.
        $jsMessage->nak();
    }

    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $this->ensure($queue);

        $consumer = $this->js->createConsumer($this->deadStream($queue), new ConsumerConfig(
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
            $this->js->publish($this->workSubject($queue), $jsMessage->getData());
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
            return $this->js->getStreamInfo($this->deadStream($queue))->state->messages;
        }

        return $this->consumers[$queue->name]['normal']->info(true)->numPending
            + $this->consumers[$queue->name]['priority']->info(true)->numPending;
    }

    public function close(): void
    {
        $this->connection->close();
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
        if (isset($this->provisioned[$queue->name])) {
            return;
        }

        $maxAge = $queue->jobTtl > 0 ? (float) $queue->jobTtl : null;

        $this->js->createOrUpdateStream(new StreamConfig(
            name: $this->workStream($queue),
            subjects: [$this->workSubject($queue), $this->prioritySubject($queue)],
            retention: RetentionPolicy::WorkQueue,
            maxAge: $maxAge,
            storage: StorageType::File,
            replicas: $this->replicas,
        ));

        $this->js->createOrUpdateStream(new StreamConfig(
            name: $this->deadStream($queue),
            subjects: [$this->deadSubject($queue)],
            retention: RetentionPolicy::WorkQueue,
            storage: StorageType::File,
            replicas: $this->replicas,
        ));

        $this->consumers[$queue->name] = [
            'normal' => $this->js->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: 'worker',
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->workSubject($queue),
            )),
            'priority' => $this->js->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: 'worker_priority',
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->prioritySubject($queue),
            )),
        ];

        $this->provisioned[$queue->name] = true;
    }

    private function workStream(Queue $queue): string
    {
        return 'QUEUE_' . $this->sanitize($queue->name);
    }

    private function deadStream(Queue $queue): string
    {
        return 'QUEUE_' . $this->sanitize($queue->name) . '_DEAD';
    }

    private function workSubject(Queue $queue): string
    {
        return "{$queue->namespace}.queue.{$queue->name}";
    }

    private function prioritySubject(Queue $queue): string
    {
        return "{$queue->namespace}.queue.{$queue->name}.priority";
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
