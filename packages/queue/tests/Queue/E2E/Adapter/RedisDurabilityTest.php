<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Exception\Conflict;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Result;
use Utopia\Queue\Queue;

final class RedisDurabilityTest extends TestCase
{
    private string $namespace;

    protected function setUp(): void
    {
        $this->namespace = 'queue-durability-' . bin2hex(random_bytes(8));
    }

    public function testProducerRetryIsAcknowledgedWithoutPublishingTwice(): void
    {
        $queue = $this->queue();
        $first = $this->broker();
        $second = $this->broker();

        $this->assertSame(
            Result::Enqueued,
            $first->enqueueOnce($queue, 'message-1', ['b' => 2, 'a' => 1]),
        );
        $this->assertSame(
            Result::Existing,
            $second->enqueueOnce($queue, 'message-1', ['a' => 1, 'b' => 2]),
        );
        $this->assertSame(1, $first->getQueueSize($queue));

        $message = $first->receive($queue, 0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('message-1', $message->getPid());
        $this->assertSame(['b' => 2, 'a' => 1], $message->getPayload());

        $first->commit($queue, $message);

        $this->assertSame(
            Result::Existing,
            $second->enqueueOnce($queue, 'message-1', ['a' => 1, 'b' => 2]),
        );
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $second->receive($queue, 0));
    }

    public function testMessageIdCannotBeReusedForAConflictingEnvelope(): void
    {
        $queue = $this->queue();
        $broker = $this->broker();

        $this->assertSame(
            Result::Enqueued,
            $broker->enqueueOnce($queue, 'message-1', ['value' => 'original']),
        );

        try {
            $broker->enqueueOnce($queue, 'message-1', ['value' => 'changed']);
            $this->fail('Expected conflicting reuse of a message ID to be rejected.');
        } catch (Conflict $error) {
            $this->assertSame('message-1', $error->messageId);
        }

        $this->expectException(Conflict::class);
        $broker->enqueueOnce($queue, 'message-1', ['value' => 'original'], priority: true);
    }

    public function testEnqueueOncePreservesPriorityOrdering(): void
    {
        $queue = $this->queue();
        $broker = $this->broker();

        $this->assertTrue($broker->enqueue($queue, ['order' => 'normal']));
        $this->assertSame(
            Result::Enqueued,
            $broker->enqueueOnce($queue, 'priority', ['order' => 'priority'], priority: true),
        );

        $priority = $broker->receive($queue, 0);
        $normal = $broker->receive($queue, 0);

        $this->assertInstanceOf(Message::class, $priority);
        $this->assertInstanceOf(Message::class, $normal);
        $this->assertSame('priority', $priority->getPayload()['order']);
        $this->assertSame('normal', $normal->getPayload()['order']);

        $broker->commit($queue, $priority);
        $broker->commit($queue, $normal);
    }

    public function testClaimResponseLossIsRecoveredAfterVisibilityTimeout(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $publisher = $this->broker();
        $publisher->enqueue($queue, ['value' => 'recover']);

        $lost = new LostClaimRedisConnection('127.0.0.1', 16379);
        $consumer = new RedisBroker($lost, $this->connection());

        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $consumer->receive($queue, 0));
        $this->assertSame(1, $lost->claims);
        $this->assertSame(0, $publisher->getQueueSize($queue));

        usleep(1_100_000);

        $message = $this->broker()->receive($queue, 0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['value' => 'recover'], $message->getPayload());
    }

    public function testExpiredClaimIsRedeliveredAndStaleAcknowledgementIsFenced(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'redeliver']);

        $first = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $first);
        $this->assertNotNull($first->getReceipt());

        usleep(1_100_000);

        $second = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $second);
        $this->assertSame($first->getPid(), $second->getPid());
        $this->assertNotSame($first->getReceipt(), $second->getReceipt());

        $broker->commit($queue, $first);

        $this->assertTrue($broker->renew($queue, $second));

        $broker->commit($queue, $second);
        $this->assertFalse($broker->renew($queue, $second));
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($queue, 0));
    }

    public function testRenewalPreventsAValidLongRunningClaimFromBeingReclaimed(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'long-running']);

        $message = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $message);

        usleep(600_000);
        $this->assertTrue($broker->renew($queue, $message));
        usleep(600_000);

        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($queue, 0));

        usleep(500_000);

        $redelivered = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $redelivered);
        $this->assertSame($message->getPid(), $redelivered->getPid());
    }

    public function testExpiredClaimCannotBeRenewedBeforeItIsReclaimed(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'expired']);

        $expired = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $expired);

        usleep(1_100_000);

        $this->assertFalse($broker->renew($queue, $expired));

        $redelivered = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $redelivered);
        $this->assertSame($expired->getPid(), $redelivered->getPid());
        $this->assertNotSame($expired->getReceipt(), $redelivered->getReceipt());
    }

    public function testVisibilityTimeoutRemainsDisabledByDefault(): void
    {
        $queue = $this->queue();
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'compatibility']);

        $message = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $message);

        usleep(1_100_000);

        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($queue, 0));
        $this->assertFalse($broker->renew($queue, $message));

        $broker->commit($queue, $message);
    }

    public function testRejectedClaimCanStillBeRetried(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'retry']);

        $failed = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $failed);
        $broker->reject($queue, $failed);

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));

        $broker->retry($queue);

        $retried = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $retried);
        $this->assertSame(['value' => 'retry'], $retried->getPayload());
        $this->assertNotSame($failed->getPid(), $retried->getPid());
        $broker->commit($queue, $retried);
    }

    public function testFailedRecordSurvivesRetryPublicationFailure(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'retry']);

        $failed = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $failed);
        $broker->reject($queue, $failed);

        $connection = $this->connection();
        $connection->set("{$queue->namespace}.queue.{$queue->name}", 'incompatible');

        $broker->retry($queue);

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));
        $connection->remove("{$queue->namespace}.queue.{$queue->name}");

        $broker->retry($queue);

        $this->assertSame(0, $broker->getQueueSize($queue, failedJobs: true));
        $retried = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $retried);
        $this->assertSame(['value' => 'retry'], $retried->getPayload());
        $broker->commit($queue, $retried);
    }

    public function testRetryResponseLossDoesNotDuplicateOrLoseTheFailedRecord(): void
    {
        $queue = $this->queue(visibilityTimeout: 1);
        $broker = $this->broker();
        $broker->enqueue($queue, ['value' => 'retry']);

        $failed = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $failed);
        $broker->reject($queue, $failed);

        $lost = new LostRetryRedisConnection('127.0.0.1', 16379);
        $retrying = new RedisBroker($lost, $lost);

        try {
            $retrying->retry($queue);
            $this->fail('Expected the stored retry response to be lost.');
        } catch (\RedisException) {
            $this->assertTrue($lost->lost);
        }

        $this->assertSame(0, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame(1, $broker->getQueueSize($queue));

        $retried = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $retried);
        $this->assertSame(['value' => 'retry'], $retried->getPayload());
        $broker->commit($queue, $retried);

        $this->assertNotInstanceOf(Message::class, $broker->receive($queue, 0));
    }

    public function testLegacyFailedRecordSurvivesFalseEnqueueResult(): void
    {
        $queue = $this->queue();
        $pid = 'legacy-message';
        $connection = new FailedEnqueueRedisConnection('127.0.0.1', 16379);
        $connection->setArray(
            "{$queue->namespace}.jobs.{$queue->name}.{$pid}",
            [
                'pid' => $pid,
                'queue' => $queue->name,
                'timestamp' => time() - 1,
                'payload' => ['value' => 'legacy'],
            ],
        );
        $connection->leftPush("{$queue->namespace}.failed.{$queue->name}", $pid);

        $broker = new RedisBroker($connection, $connection);
        $broker->retry($queue);

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame(0, $broker->getQueueSize($queue));

        $recovered = $this->broker();
        $recovered->retry($queue);

        $this->assertSame(0, $recovered->getQueueSize($queue, failedJobs: true));
        $message = $recovered->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['value' => 'legacy'], $message->getPayload());
        $recovered->commit($queue, $message);
    }

    public function testConcurrentProducerRetriesPublishExactlyOnce(): void
    {
        $queue = $this->queue();
        $results = [];

        \Swoole\Coroutine\run(function () use ($queue, &$results): void {
            $channel = new \Swoole\Coroutine\Channel(20);

            for ($producer = 0; $producer < 20; $producer++) {
                go(function () use ($queue, $channel): void {
                    $channel->push(
                        $this->broker()->enqueueOnce(
                            $queue,
                            'shared-message',
                            ['value' => 'same'],
                        ),
                    );
                });
            }

            for ($producer = 0; $producer < 20; $producer++) {
                $results[] = $channel->pop();
            }
        });

        $enqueued = array_filter($results, static fn(Result $result): bool => $result === Result::Enqueued);
        $existing = array_filter($results, static fn(Result $result): bool => $result === Result::Existing);

        $this->assertCount(1, $enqueued);
        $this->assertCount(19, $existing);
        $this->assertSame(1, $this->broker()->getQueueSize($queue));
    }

    private function broker(): RedisBroker
    {
        return new RedisBroker($this->connection(), $this->connection());
    }

    private function connection(): RedisConnection
    {
        return new RedisConnection('127.0.0.1', 16379);
    }

    private function queue(int $visibilityTimeout = 0): Queue
    {
        return new Queue(
            'durability',
            $this->namespace,
            visibilityTimeout: $visibilityTimeout,
        );
    }
}

final class LostClaimRedisConnection extends RedisConnection
{
    public int $claims = 0;

    #[\Override]
    public function evaluate(string $script, array $keys = [], array $arguments = []): mixed
    {
        $result = parent::evaluate($script, $keys, $arguments);
        $this->claims++;

        if ($this->claims === 1) {
            throw new \RedisException('The claim was stored, but its response was lost.');
        }

        return $result;
    }
}

final class LostRetryRedisConnection extends RedisConnection
{
    public bool $lost = false;

    #[\Override]
    public function evaluate(string $script, array $keys = [], array $arguments = []): mixed
    {
        $result = parent::evaluate($script, $keys, $arguments);

        if (!$this->lost && str_contains($script, '-- queue:retry')) {
            $this->lost = true;
            throw new \RedisException('The retry was stored, but its response was lost.');
        }

        return $result;
    }
}

final class FailedEnqueueRedisConnection extends RedisConnection
{
    #[\Override]
    public function leftPushArray(string $queue, array $value): bool
    {
        return false;
    }
}
