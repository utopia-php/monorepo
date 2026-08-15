<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Job;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

final class ServerJobsTest extends TestCase
{
    public function testJobRegistersIndependentCoroutineCaps(): void
    {
        $server = new Server(new RecordingAdapter());

        $functions = $server->job('v1-functions', 8);
        $databases = $server->job('database_db_main', 1);

        $this->assertInstanceOf(Job::class, $functions);
        $this->assertInstanceOf(Job::class, $databases);
        $this->assertNotSame($functions, $databases);
        $this->assertSame(8, $server->coroutines('v1-functions'));
        $this->assertSame(1, $server->coroutines('database_db_main'));
        $this->assertCount(2, $server->jobs());
    }

    public function testOmittedCoroutineCapInheritsAdapter(): void
    {
        $server = new Server(new RecordingAdapter(queue: 'v1-mails', coroutines: 5));

        $server->job('v1-mails');

        $this->assertSame(5, $server->coroutines('v1-mails'));
    }

    public function testBareJobRequiresAdapterDefaultQueue(): void
    {
        $server = new Server(new RecordingAdapter(queue: null));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Queue name is required');

        $server->job();
    }

    public function testBareJobInheritsAdapterQueue(): void
    {
        $server = new Server(new RecordingAdapter(queue: 'v1-mails'));

        $server->job();

        $this->assertArrayHasKey('v1-mails', $server->jobs());
    }

    public function testConsumeKeepsPerQueueCaps(): void
    {
        $adapter = new RecordingAdapter();

        $adapter->consume(
            static fn(): null => null,
            static fn(): null => null,
            static fn(): null => null,
            [
                [
                    'queue' => new Queue('database_db_main'),
                    'maxCoroutines' => 1,
                ],
                [
                    'queue' => new Queue('v1-functions'),
                    'maxCoroutines' => 8,
                ],
            ],
        );

        $this->assertSame(
            [
                ['queue' => 'database_db_main', 'maxCoroutines' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
            ],
            $adapter->consumed,
        );
    }

    public function testStartDrivesConsumeFromJobsEvenForOneQueue(): void
    {
        $adapter = new RecordingAdapter(queue: null);
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame(
            [
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
            ],
            $adapter->consumed,
        );
    }

    public function testStartWithMultipleJobsUsesConsume(): void
    {
        $adapter = new RecordingAdapter(queue: null);
        $server = new Server($adapter);
        $server->job('database_db_main', 1);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame(
            [
                ['queue' => 'database_db_main', 'maxCoroutines' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
            ],
            $adapter->consumed,
        );
    }
}

final class FakeConsumer implements Consumer
{
    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class RecordingAdapter extends Adapter
{
    /**
     * @var list<array{queue: string, maxCoroutines: int}>
     */
    public array $consumed = [];

    /** @var callable[] */
    private array $onWorkerStart = [];

    private readonly int $defaultCoroutines;

    public function __construct(?string $queue = 'v1-functions', int $coroutines = 1)
    {
        parent::__construct(new FakeConsumer(), 1, $queue);
        $this->defaultCoroutines = max(1, $coroutines);
    }

    #[\Override]
    public function coroutines(): int
    {
        return $this->defaultCoroutines;
    }

    public function start(): self
    {
        foreach ($this->onWorkerStart as $callback) {
            $callback('0');
        }

        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        $this->onWorkerStart[] = $callback;

        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }

    #[\Override]
    protected function run(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        ?Consumer $consumer = null,
    ): void {
        $this->consumed[] = [
            'queue' => $queue->name,
            'maxCoroutines' => $maxCoroutines,
        ];
    }
}
