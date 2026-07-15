<?php

namespace Utopia\Queue\Broker;

use Utopia\Queue\Broker\Redis\Script;
use Utopia\Queue\Claim;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Atomic;
use Utopia\Queue\Consumer;
use Utopia\Queue\Consumer\Recoverable;
use Utopia\Queue\Message;
use Utopia\Queue\Option\Reliable;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

class Redis implements Publisher, Consumer, Recoverable
{
    private const int POP_TIMEOUT = 2;
    private const int RECONNECT_BACKOFF_MS = 100;
    private const int RECONNECT_MAX_BACKOFF_MS = 5_000;

    private bool $closed = false;
    private int $reconnectAttempt = 0;
    private int $reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
    /**
     * @var (callable(Queue, \Throwable, int, int): void)|null
     */
    private $reconnectCallback;
    /**
     * @var (callable(Queue, int): void)|null
     */
    private $reconnectSuccessCallback;

    public function __construct(
        // Blocking receive loop + claim writes (single caller).
        private readonly Connection $receive,
        // Acks and publishing; wrap in Locking when shared by coroutines.
        private readonly Connection $commands,
    ) {}

    public function setReconnectCallback(?callable $callback): self
    {
        $this->reconnectCallback = $callback;

        return $this;
    }

    public function setReconnectSuccessCallback(?callable $callback): self
    {
        $this->reconnectSuccessCallback = $callback;

        return $this;
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        if ($queue->reliable instanceof Reliable) {
            return $this->receiveReliable($queue, $timeout);
        }

        return $this->receiveLegacy($queue, $timeout);
    }

    private function receiveLegacy(Queue $queue, int $timeout): ?Message
    {
        if ($this->isClosed()) {
            return null;
        }

        try {
            $nextMessage = $this->receive->rightPopArray("{$queue->namespace}.queue.{$queue->name}", $timeout);
            if ($this->reconnectAttempt > 0) {
                $this->triggerReconnectSuccessCallback($queue, $this->reconnectAttempt);
            }

            $this->reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
            $this->reconnectAttempt = 0;
        } catch (\RedisException|\RedisClusterException $e) {
            if ($this->isClosed()) {
                return null;
            }

            $this->reconnectAttempt++;

            try {
                $this->receive->close();
            } catch (\Throwable) {
            }

            $sleepMs = mt_rand(0, $this->reconnectBackoffMs);
            $this->triggerReconnectCallback($queue, $e, $this->reconnectAttempt, $sleepMs);

            usleep($sleepMs * 1000);
            $this->reconnectBackoffMs = min(self::RECONNECT_MAX_BACKOFF_MS, $this->reconnectBackoffMs * 2);

            return null;
        }

        if (!$nextMessage) {
            return null;
        }

        $nextMessage['timestamp'] = (int) $nextMessage['timestamp'];

        $message = new Message($nextMessage);
        $pid = $message->getPid();

        // Claim: store the job, mark it processing, bump received stats.
        $this->receive->setArray("{$queue->namespace}.jobs.{$queue->name}.{$pid}", $nextMessage, $queue->jobTtl);
        $this->receive->leftPush("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->receive->increment("{$queue->namespace}.stats.{$queue->name}.total");
        $this->receive->increment("{$queue->namespace}.stats.{$queue->name}.processing");

        return $message;
    }

    public function commit(Queue $queue, Message $message): void
    {
        if ($queue->reliable instanceof Reliable) {
            $this->commitReliable($queue, $message);

            return;
        }

        $pid = $message->getPid();

        $this->commands->remove("{$queue->namespace}.jobs.{$queue->name}.{$pid}");
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.success");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    public function reject(Queue $queue, Message $message): void
    {
        if ($queue->reliable instanceof Reliable) {
            $this->rejectReliable($queue, $message);

            return;
        }

        $pid = $message->getPid();

        $this->commands->leftPush("{$queue->namespace}.failed.{$queue->name}", $pid);
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.failed");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @phpstan-impure close() flips this from another coroutine mid-receive(). */
    private function isClosed(): bool
    {
        return $this->closed;
    }

    private function triggerReconnectCallback(Queue $queue, \Throwable $error, int $attempt, int $sleepMs): void
    {
        if (!\is_callable($this->reconnectCallback)) {
            return;
        }

        try {
            ($this->reconnectCallback)($queue, $error, $attempt, $sleepMs);
        } catch (\Throwable) {
        }
    }

    private function triggerReconnectSuccessCallback(Queue $queue, int $attempts): void
    {
        if (!\is_callable($this->reconnectSuccessCallback)) {
            return;
        }

        try {
            ($this->reconnectSuccessCallback)($queue, $attempts);
        } catch (\Throwable) {
        }
    }

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        if ($queue->reliable instanceof Reliable) {
            $atomic = $this->atomic($this->commands);
            $result = $atomic->evaluate(
                Script::ENQUEUE,
                [
                    $this->pendingKey($queue),
                    $this->pid(),
                    $queue->name,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    $priority ? '1' : '0',
                ],
                1,
            );

            return (int) $result === 1;
        }

        $payload = [
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];
        if ($priority) {
            return $this->commands->rightPushArray("{$queue->namespace}.queue.{$queue->name}", $payload);
        }
        return $this->commands->leftPushArray("{$queue->namespace}.queue.{$queue->name}", $payload);
    }

    /**
     * Take all jobs from the failed queue and re-enqueue them.
     * @param int|null $limit The amount of jobs to retry
     */
    public function retry(Queue $queue, ?int $limit = null): void
    {
        if ($queue->reliable instanceof Reliable) {
            $this->retryReliable($queue, $limit);

            return;
        }

        $start = time();
        $processed = 0;

        while (true) {
            $pid = $this->commands->rightPop("{$queue->namespace}.failed.{$queue->name}", self::POP_TIMEOUT);

            // No more jobs to retry
            if ($pid === false) {
                break;
            }

            $job = $this->getJob($queue, $pid);

            // Job doesn't exist
            if ($job === false) {
                break;
            }

            // Job was already retried
            if ($job->getTimestamp() >= $start) {
                break;
            }

            // We're reached the max amount of jobs to retry
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $this->enqueue($queue, $job->getPayload());
            $processed++;
        }
    }

    private function getJob(Queue $queue, string $pid): Message|false
    {
        $value = $this->commands->get("{$queue->namespace}.jobs.{$queue->name}.{$pid}");

        // Missing/expired jobs return false or null depending on the driver.
        if (!\is_string($value)) {
            return false;
        }

        $job = json_decode($value, true);

        return \is_array($job) ? new Message($job) : false;
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        if ($queue->reliable instanceof Reliable) {
            $this->atomic($this->commands);
        }

        $queueName = $this->pendingKey($queue);
        if ($failedJobs) {
            if ($queue->reliable instanceof Reliable) {
                $queueName = $this->atomicKey($queue, 'failed');
            } else {
                $queueName = "{$queue->namespace}.failed.{$queue->name}";
            }
        }
        return $this->commands->listSize($queueName);
    }

    public function extend(Queue $queue, Message $message): bool
    {
        $reliable = $this->reliable($queue);
        $claimedAt = $this->claimedAt($message);
        $result = $this->atomic($this->commands)->evaluate(
            Script::EXTEND,
            [
                $this->atomicKey($queue, 'jobs'),
                $this->atomicKey($queue, 'processing'),
                $message->getPid(),
                $claimedAt,
                (string) $reliable->visibility,
            ],
            2,
        );

        return (int) $result === 1;
    }

    public function expired(Queue $queue, int $limit): array
    {
        $this->reliable($queue);
        if ($limit <= 0) {
            return [];
        }

        $result = $this->atomic($this->commands)->evaluate(
            Script::EXPIRED,
            [
                $this->atomicKey($queue, 'processing'),
                $this->atomicKey($queue, 'jobs'),
                (string) $limit,
            ],
            2,
        );
        if (!\is_array($result)) {
            throw new \UnexpectedValueException('Reliable expired scan returned an invalid response.');
        }

        $claims = [];
        $counter = \count($result);
        for ($index = 0; $index + 1 < $counter; $index += 2) {
            $pid = $result[$index];
            $claimedAt = $result[$index + 1];
            if (!\is_string($pid) || !\is_string($claimedAt)) {
                throw new \UnexpectedValueException('Reliable expired scan returned an invalid claim.');
            }
            $claims[] = new Claim($pid, $claimedAt === '' ? null : $claimedAt);
        }

        return $claims;
    }

    public function reclaim(Queue $queue, Claim $claim): ?Message
    {
        $this->reliable($queue);
        $result = $this->atomic($this->commands)->evaluate(
            Script::RECLAIM,
            [
                $this->atomicKey($queue, 'processing'),
                $this->atomicKey($queue, 'jobs'),
                $this->pendingKey($queue),
                $this->atomicKey($queue, 'quarantine'),
                $this->statKey($queue, 'processing'),
                $this->statKey($queue, 'reclaimed'),
                $this->statKey($queue, 'quarantined'),
                $claim->pid,
                $claim->claimedAt ?? '',
                $this->pid(),
            ],
            7,
        );

        if (!\is_array($result) || !isset($result[0])) {
            throw new \UnexpectedValueException('Reliable reclaim returned an invalid response.');
        }
        if ((int) $result[0] !== 1) {
            return null;
        }
        if (!isset($result[1]) || !\is_string($result[1])) {
            throw new \UnexpectedValueException('Reliable reclaim returned an invalid message.');
        }

        return $this->message($result[1]);
    }

    private function receiveReliable(Queue $queue, int $timeout): ?Message
    {
        if ($this->isClosed()) {
            return null;
        }

        $reliable = $this->reliable($queue);
        $atomic = $this->atomic($this->receive);
        $deadline = hrtime(true) + (max(0, $timeout) * 1_000_000_000);
        $backoff = $reliable->pollMinimum;

        while (!$this->isClosed()) {
            try {
                $result = $atomic->evaluate(
                    Script::CLAIM,
                    [
                        $this->pendingKey($queue),
                        $this->atomicKey($queue, 'jobs'),
                        $this->atomicKey($queue, 'processing'),
                        $this->atomicKey($queue, 'quarantine'),
                        $this->statKey($queue, 'total'),
                        $this->statKey($queue, 'processing'),
                        $this->statKey($queue, 'quarantined'),
                        (string) $reliable->visibility,
                    ],
                    7,
                );
                $this->reconnectSucceeded($queue);
            } catch (\RedisException|\RedisClusterException $error) {
                $remaining = max(0, $deadline - hrtime(true));
                $this->reconnectFailed($queue, $error, intdiv($remaining, 1_000_000));

                if ($this->isClosed() || $timeout === 0 || hrtime(true) >= $deadline) {
                    return null;
                }

                continue;
            }

            if (!\is_array($result) || !isset($result[0])) {
                throw new \UnexpectedValueException('Reliable claim returned an invalid response.');
            }
            if ((int) $result[0] === 1) {
                if (!isset($result[1], $result[2]) || !\is_string($result[1]) || !\is_string($result[2])) {
                    throw new \UnexpectedValueException('Reliable claim returned an invalid message.');
                }

                return $this->message($result[1], $result[2]);
            }

            $remaining = $deadline - hrtime(true);
            if ($timeout === 0 || $remaining <= 0) {
                return null;
            }

            $maximum = min($reliable->pollMaximum, $backoff);
            $sleep = mt_rand($reliable->pollMinimum, $maximum) * 1_000_000;
            $sleep = min($sleep, $remaining);
            if ($sleep > 0) {
                usleep(max(1, intdiv($sleep, 1_000)));
            }
            $backoff = min($reliable->pollMaximum, $backoff * 2);
        }

        return null;
    }

    private function commitReliable(Queue $queue, Message $message): void
    {
        $this->reliable($queue);
        $this->atomic($this->commands)->evaluate(
            Script::COMMIT,
            [
                $this->atomicKey($queue, 'jobs'),
                $this->atomicKey($queue, 'processing'),
                $this->statKey($queue, 'success'),
                $this->statKey($queue, 'processing'),
                $message->getPid(),
                $this->claimedAt($message),
            ],
            4,
        );
    }

    private function rejectReliable(Queue $queue, Message $message): void
    {
        $this->reliable($queue);
        $this->atomic($this->commands)->evaluate(
            Script::REJECT,
            [
                $this->atomicKey($queue, 'jobs'),
                $this->atomicKey($queue, 'processing'),
                $this->atomicKey($queue, 'failed'),
                $this->statKey($queue, 'failed'),
                $this->statKey($queue, 'processing'),
                $message->getPid(),
                $this->claimedAt($message),
            ],
            5,
        );
    }

    private function retryReliable(Queue $queue, ?int $limit): void
    {
        $this->reliable($queue);
        $atomic = $this->atomic($this->commands);
        $processed = 0;

        while ($limit === null || $processed < $limit) {
            $result = $atomic->evaluate(
                Script::RETRY,
                [
                    $this->atomicKey($queue, 'failed'),
                    $this->atomicKey($queue, 'jobs'),
                    $this->pendingKey($queue),
                    $this->atomicKey($queue, 'quarantine'),
                    $this->statKey($queue, 'retried'),
                    $this->statKey($queue, 'quarantined'),
                    $this->pid(),
                ],
                6,
            );
            if (!\is_array($result) || !isset($result[0])) {
                throw new \UnexpectedValueException('Reliable retry returned an invalid response.');
            }

            $status = (int) $result[0];
            if ($status === 0) {
                return;
            }
            if ($status === 1) {
                $processed++;
            }
        }
    }

    private function atomic(Connection $connection): Atomic
    {
        if (!$connection instanceof Atomic || !$connection->supportsAtomic()) {
            throw new \LogicException('Reliable queues require a single Redis connection with atomic scripting support.');
        }

        return $connection;
    }

    private function reliable(Queue $queue): Reliable
    {
        if (!$queue->reliable instanceof Reliable) {
            throw new \LogicException('Recovery operations require a reliable queue.');
        }

        return $queue->reliable;
    }

    private function claimedAt(Message $message): string
    {
        return $message->getClaimedAt()
            ?? throw new \LogicException('Reliable messages require claim metadata.');
    }

    private function message(string $encoded, ?string $claimedAt = null): Message
    {
        $value = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('Reliable message envelope must be an array.');
        }
        $value['timestamp'] = (int) ($value['timestamp'] ?? 0);

        return new Message($value, $claimedAt);
    }

    private function pendingKey(Queue $queue): string
    {
        return "{$queue->namespace}.queue.{$queue->name}";
    }

    private function atomicKey(Queue $queue, string $type): string
    {
        return "{$queue->namespace}.atomic.{$type}.{$queue->name}";
    }

    private function statKey(Queue $queue, string $stat): string
    {
        return "{$queue->namespace}.stats.{$queue->name}.{$stat}";
    }

    private function pid(): string
    {
        return uniqid(more_entropy: true);
    }

    private function reconnectSucceeded(Queue $queue): void
    {
        if ($this->reconnectAttempt > 0) {
            $this->triggerReconnectSuccessCallback($queue, $this->reconnectAttempt);
        }

        $this->reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
        $this->reconnectAttempt = 0;
    }

    private function reconnectFailed(Queue $queue, \Throwable $error, int $maximumSleepMs): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->reconnectAttempt++;
        try {
            $this->receive->close();
        } catch (\Throwable) {
        }

        $sleepMs = mt_rand(0, min($this->reconnectBackoffMs, max(0, $maximumSleepMs)));
        $this->triggerReconnectCallback($queue, $error, $this->reconnectAttempt, $sleepMs);
        usleep($sleepMs * 1000);
        $this->reconnectBackoffMs = min(self::RECONNECT_MAX_BACKOFF_MS, $this->reconnectBackoffMs * 2);
    }
}
