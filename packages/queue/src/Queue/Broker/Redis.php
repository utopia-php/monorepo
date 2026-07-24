<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Utopia\Queue\Broker\Redis\Keys;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Lua;
use Utopia\Queue\Consumer\Leased as LeasedConsumer;
use Utopia\Queue\Exception\Conflict;
use Utopia\Queue\Exception\Unsupported;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Idempotent as IdempotentPublisher;
use Utopia\Queue\Publisher\Result;
use Utopia\Queue\Queue;

class Redis implements IdempotentPublisher, LeasedConsumer
{
    private const string ACKNOWLEDGE_SCRIPT = <<<'LUA'
        -- queue:acknowledge
        local receipt = redis.call('HGET', KEYS[2], ARGV[1])
        if not receipt or receipt ~= ARGV[2] then
            return 0
        end

        redis.call('HDEL', KEYS[1], ARGV[1])
        redis.call('HDEL', KEYS[2], ARGV[1])
        redis.call('ZREM', KEYS[3], ARGV[1])
        redis.call('HDEL', KEYS[4], ARGV[1])

        return 1
        LUA;

    private const string CLAIM_SCRIPT = <<<'LUA'
        -- queue:claim
        local now = tonumber(ARGV[1])
        local visibility = tonumber(ARGV[2])
        local receipt = ARGV[3]
        local ttl = tonumber(ARGV[4])
        local expired = 0

        if visibility > 0 then
            while true do
                local entries = redis.call('ZRANGEBYSCORE', KEYS[4], '-inf', now, 'LIMIT', 0, 1)
                if #entries == 0 then
                    break
                end

                local pid = entries[1]
                local message = redis.call('HGET', KEYS[2], pid)
                local currentReceipt = redis.call('HGET', KEYS[3], pid)
                local expiresAt = tonumber(redis.call('HGET', KEYS[5], pid) or '0')

                if not message or not currentReceipt or (expiresAt > 0 and expiresAt <= now) then
                    redis.call('HDEL', KEYS[2], pid)
                    redis.call('HDEL', KEYS[3], pid)
                    redis.call('ZREM', KEYS[4], pid)
                    redis.call('HDEL', KEYS[5], pid)
                    expired = expired + 1
                else
                    redis.call('HSET', KEYS[3], pid, receipt)
                    redis.call('ZADD', KEYS[4], now + visibility, pid)

                    return { message, receipt, tostring(expired), 'reclaimed' }
                end
            end
        end

        local message = redis.call('LINDEX', KEYS[1], -1)
        if not message then
            return { '', '', tostring(expired), 'empty' }
        end

        local decoded = cjson.decode(message)
        if type(decoded) ~= 'table' or type(decoded.pid) ~= 'string' or decoded.pid == '' then
            return redis.error_reply('Queue envelope must contain a non-empty string pid')
        end

        redis.call('RPOP', KEYS[1])
        redis.call('HSET', KEYS[2], decoded.pid, message)
        redis.call('HSET', KEYS[3], decoded.pid, receipt)
        if visibility > 0 then
            redis.call('ZADD', KEYS[4], now + visibility, decoded.pid)
        end
        if ttl > 0 then
            redis.call('HSET', KEYS[5], decoded.pid, now + ttl)
        else
            redis.call('HSET', KEYS[5], decoded.pid, 0)
        end

        return { message, receipt, tostring(expired), 'new' }
        LUA;

    private const string ENQUEUE_ONCE_SCRIPT = <<<'LUA'
        -- queue:enqueue-once
        local queueType = redis.call('TYPE', KEYS[1]).ok
        if queueType ~= 'none' and queueType ~= 'list' then
            return redis.error_reply('Pending queue key has an incompatible Redis type')
        end

        local ledgerType = redis.call('TYPE', KEYS[2]).ok
        if ledgerType ~= 'none' and ledgerType ~= 'hash' then
            return redis.error_reply('Idempotency ledger key has an incompatible Redis type')
        end

        local fingerprint = redis.call('HGET', KEYS[2], ARGV[1])
        if fingerprint then
            if fingerprint == ARGV[2] then
                return 0
            end

            return -1
        end

        if ARGV[4] == '1' then
            redis.call('RPUSH', KEYS[1], ARGV[3])
        else
            redis.call('LPUSH', KEYS[1], ARGV[3])
        end
        redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])

        return 1
        LUA;

    private const int POLL_INTERVAL_MICROSECONDS = 100_000;
    private const int RECONNECT_BACKOFF_MS = 100;
    private const int RECONNECT_MAX_BACKOFF_MS = 5_000;

    private const string REJECT_SCRIPT = <<<'LUA'
        -- queue:reject
        local receipt = redis.call('HGET', KEYS[2], ARGV[1])
        if not receipt or receipt ~= ARGV[2] then
            return 0
        end

        local message = redis.call('HGET', KEYS[1], ARGV[1])
        if not message then
            return 0
        end

        local expiresAt = tonumber(redis.call('HGET', KEYS[4], ARGV[1]) or '0')
        local failure = cjson.encode({
            message = cjson.decode(message),
            expiresAt = expiresAt,
        })

        redis.call('LPUSH', KEYS[5], failure)
        redis.call('HDEL', KEYS[1], ARGV[1])
        redis.call('HDEL', KEYS[2], ARGV[1])
        redis.call('ZREM', KEYS[3], ARGV[1])
        redis.call('HDEL', KEYS[4], ARGV[1])

        return 1
        LUA;

    private const string RENEW_SCRIPT = <<<'LUA'
        -- queue:renew
        local receipt = redis.call('HGET', KEYS[1], ARGV[1])
        if not receipt or receipt ~= ARGV[2] then
            return 0
        end

        local deadline = tonumber(redis.call('ZSCORE', KEYS[2], ARGV[1]) or '0')
        if deadline <= tonumber(ARGV[3]) then
            return 0
        end

        redis.call('ZADD', KEYS[2], ARGV[4], ARGV[1])

        return 1
        LUA;

    private const string RETRY_SCRIPT = <<<'LUA'
        -- queue:retry
        local failed = redis.call('LINDEX', KEYS[1], -1)
        if not failed then
            return 0
        end

        if failed ~= ARGV[1] then
            return -1
        end

        if ARGV[2] ~= '' then
            redis.call('LPUSH', KEYS[2], ARGV[2])
        end
        redis.call('RPOP', KEYS[1])

        return 1
        LUA;

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
        private readonly Connection $receive,
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

    #[\Override]
    public function receive(Queue $queue, int $timeout): ?Message
    {
        if ($this->isClosed()) {
            return null;
        }

        $deadline = hrtime(true) + (max(0, $timeout) * 1_000_000_000);

        do {
            try {
                $claim = $this->claim($queue);
                if ($this->reconnectAttempt > 0) {
                    $this->triggerReconnectSuccessCallback($queue, $this->reconnectAttempt);
                }

                $this->reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
                $this->reconnectAttempt = 0;
            } catch (\RedisException|\RedisClusterException $error) {
                $this->reconnect($queue, $error);

                return null;
            }

            if ($claim['expired'] > 0) {
                $this->decrementProcessing($queue, $claim['expired']);
            }

            if ($claim['message'] instanceof Message) {
                $this->receive->increment("{$queue->namespace}.stats.{$queue->name}.total");
                if ($claim['new']) {
                    $this->receive->increment("{$queue->namespace}.stats.{$queue->name}.processing");
                }

                return $claim['message'];
            }

            if ($timeout <= 0 || hrtime(true) >= $deadline || $this->isClosed()) {
                return null;
            }

            $remaining = (int) max(0, ($deadline - hrtime(true)) / 1_000);
            usleep(min(self::POLL_INTERVAL_MICROSECONDS, $remaining));
        } while (!$this->isClosed());

        return null;
    }

    #[\Override]
    public function commit(Queue $queue, Message $message): void
    {
        $receipt = $message->getReceipt();
        if ($receipt === null) {
            $this->commitLegacy($queue, $message);

            return;
        }

        $keys = Keys::from($queue);
        $acknowledged = $this->lua($this->commands)->evaluate(
            self::ACKNOWLEDGE_SCRIPT,
            [
                $keys->processing,
                $keys->receipts,
                $keys->visibility,
                $keys->expiry,
            ],
            [$message->getPid(), $receipt],
        );

        if ((int) $acknowledged !== 1) {
            return;
        }

        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.success");
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    #[\Override]
    public function reject(Queue $queue, Message $message): void
    {
        $receipt = $message->getReceipt();
        if ($receipt === null) {
            $this->rejectLegacy($queue, $message);

            return;
        }

        $keys = Keys::from($queue);
        $rejected = $this->lua($this->commands)->evaluate(
            self::REJECT_SCRIPT,
            [
                $keys->processing,
                $keys->receipts,
                $keys->visibility,
                $keys->expiry,
                $keys->failed,
            ],
            [$message->getPid(), $receipt],
        );

        if ((int) $rejected !== 1) {
            return;
        }

        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.failed");
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    #[\Override]
    public function renew(Queue $queue, Message $message): bool
    {
        $receipt = $message->getReceipt();
        if ($queue->visibilityTimeout === 0 || $receipt === null) {
            return false;
        }

        $keys = Keys::from($queue);
        $now = $this->now();
        $renewed = $this->lua($this->commands)->evaluate(
            self::RENEW_SCRIPT,
            [$keys->receipts, $keys->visibility],
            [
                $message->getPid(),
                $receipt,
                $now,
                $now + ($queue->visibilityTimeout * 1_000),
            ],
        );

        return (int) $renewed === 1;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        $message = [
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];

        if ($priority) {
            return $this->commands->rightPushArray(Keys::from($queue)->pending, $message);
        }

        return $this->commands->leftPushArray(Keys::from($queue)->pending, $message);
    }

    #[\Override]
    public function enqueueOnce(
        Queue $queue,
        string $messageId,
        array $payload,
        bool $priority = false,
    ): Result {
        if ($messageId === '') {
            throw new \InvalidArgumentException('Queue message ID cannot be empty.');
        }

        $keys = Keys::from($queue);
        $message = [
            'pid' => $messageId,
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];
        $encoded = json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $fingerprint = hash(
            'sha256',
            json_encode(
                [
                    'payload' => $this->canonicalize($payload),
                    'priority' => $priority,
                ],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );

        $result = (int) $this->lua($this->commands)->evaluate(
            self::ENQUEUE_ONCE_SCRIPT,
            [$keys->pending, $keys->ledger],
            [
                $messageId,
                $fingerprint,
                $encoded,
                $priority ? 1 : 0,
            ],
        );

        return match ($result) {
            1 => Result::Enqueued,
            0 => Result::Existing,
            -1 => throw new Conflict($messageId),
            default => throw new \UnexpectedValueException("Unexpected enqueue-once result: {$result}."),
        };
    }

    #[\Override]
    public function retry(Queue $queue, ?int $limit = null): void
    {
        $processed = 0;
        $keys = Keys::from($queue);

        foreach ([$keys->failed, $this->legacyFailedKey($queue)] as $failedQueue) {
            $available = $this->commands->listSize($failedQueue);

            for ($attempt = 0; $attempt < $available; $attempt++) {
                if ($limit !== null && $processed >= $limit) {
                    return;
                }

                $failed = $this->commands->listRange($failedQueue, 1, -1)[0] ?? null;
                if (!\is_string($failed)) {
                    break;
                }

                $job = $failedQueue === $keys->failed
                    ? $this->getFailedJob($failed)
                    : $this->getJob($queue, $failed);

                if ($failedQueue === $keys->failed) {
                    $replacement = $job instanceof Message
                        ? json_encode(
                            [
                                'pid' => $job->getPid(),
                                'queue' => $queue->name,
                                'timestamp' => time(),
                                'payload' => $job->getPayload(),
                            ],
                            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                        )
                        : '';
                    $retried = (int) $this->lua($this->commands)->evaluate(
                        self::RETRY_SCRIPT,
                        [$keys->failed, $keys->pending],
                        [$failed, $replacement],
                    );

                    if ($retried === 0) {
                        break;
                    }
                    if ($retried !== 1) {
                        continue;
                    }
                    if (!$job instanceof Message) {
                        continue;
                    }

                    $processed++;
                    continue;
                }

                if (!$job instanceof Message) {
                    $this->commands->listRemove($failedQueue, $failed);
                    continue;
                }

                $this->enqueueOnce(
                    $queue,
                    $job->getPid(),
                    $job->getPayload(),
                );

                $this->commands->listRemove($failedQueue, $failed);
                $processed++;
            }
        }
    }

    #[\Override]
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $keys = Keys::from($queue);
        if (!$failedJobs) {
            return $this->commands->listSize($keys->pending);
        }

        return $this->commands->listSize($keys->failed)
            + $this->commands->listSize($this->legacyFailedKey($queue));
    }

    /**
     * @return array{message: ?Message, expired: int, new: bool}
     */
    private function claim(Queue $queue): array
    {
        $keys = Keys::from($queue);
        $result = $this->lua($this->receive)->evaluate(
            self::CLAIM_SCRIPT,
            [
                $keys->pending,
                $keys->processing,
                $keys->receipts,
                $keys->visibility,
                $keys->expiry,
            ],
            [
                $this->now(),
                $queue->visibilityTimeout * 1_000,
                bin2hex(random_bytes(16)),
                $queue->jobTtl * 1_000,
            ],
        );

        if (!\is_array($result) || \count($result) < 4) {
            return [
                'message' => null,
                'expired' => 0,
                'new' => false,
            ];
        }

        $expired = (int) $result[2];
        if ($result[3] === 'empty') {
            return [
                'message' => null,
                'expired' => $expired,
                'new' => false,
            ];
        }

        $message = json_decode((string) $result[0], true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($message)) {
            throw new \UnexpectedValueException('Claimed queue envelope is not an object.');
        }

        return [
            'message' => new Message($message)->setReceipt((string) $result[1]),
            'expired' => $expired,
            'new' => $result[3] === 'new',
        ];
    }

    private function commitLegacy(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();

        $this->commands->remove("{$queue->namespace}.jobs.{$queue->name}.{$pid}");
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.success");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    private function rejectLegacy(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();

        $this->commands->leftPush($this->legacyFailedKey($queue), $pid);
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.failed");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    private function decrementProcessing(Queue $queue, int $messages): void
    {
        for ($message = 0; $message < $messages; $message++) {
            $this->receive->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
        }
    }

    /**
     * @return array<mixed>
     */
    private function canonicalize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (\is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        if (!array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }

        return $payload;
    }

    private function getFailedJob(string $failed): Message|false|null
    {
        $failure = json_decode($failed, true);
        if (!\is_array($failure) || !\is_array($failure['message'] ?? null)) {
            return false;
        }

        $expiresAt = (int) ($failure['expiresAt'] ?? 0);
        if ($expiresAt > 0 && $expiresAt <= $this->now()) {
            return null;
        }

        return new Message($failure['message']);
    }

    private function getJob(Queue $queue, string $pid): Message|false
    {
        $value = $this->commands->get("{$queue->namespace}.jobs.{$queue->name}.{$pid}");
        if (!\is_string($value)) {
            return false;
        }

        $job = json_decode($value, true);

        return \is_array($job) ? new Message($job) : false;
    }

    private function legacyFailedKey(Queue $queue): string
    {
        return "{$queue->namespace}.failed.{$queue->name}";
    }

    private function lua(Connection $connection): Lua
    {
        if (!$connection instanceof Lua) {
            throw new Unsupported('atomic Lua scripts');
        }

        return $connection;
    }

    private function now(): int
    {
        return (int) floor(microtime(true) * 1_000);
    }

    private function reconnect(Queue $queue, \Throwable $error): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->reconnectAttempt++;

        try {
            $this->receive->close();
        } catch (\Throwable) {
        }

        $sleepMs = mt_rand(0, $this->reconnectBackoffMs);
        $this->triggerReconnectCallback($queue, $error, $this->reconnectAttempt, $sleepMs);

        usleep($sleepMs * 1_000);
        $this->reconnectBackoffMs = min(
            self::RECONNECT_MAX_BACKOFF_MS,
            $this->reconnectBackoffMs * 2,
        );
    }

    /** @phpstan-impure close() flips this from another coroutine mid-receive(). */
    private function isClosed(): bool
    {
        return $this->closed;
    }

    private function triggerReconnectCallback(
        Queue $queue,
        \Throwable $error,
        int $attempt,
        int $sleepMs,
    ): void {
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
}
