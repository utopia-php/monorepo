<?php

namespace Utopia\Queue\Adapter;

use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

/**
 * Run jobs in the publishing process instead of handing them to a broker.
 *
 * `enqueue()` invokes the Server job for that queue immediately and returns
 * when the handler finishes. `start()` arms the handlers and returns — there
 * is no consume loop and no worker process.
 *
 * Use this when a single process should handle HTTP (or CLI) and its
 * side-effects without a queue. Failed handlers are reported through the
 * Server error hook; `enqueue()` still returns true, matching a broker that
 * accepted the message.
 */
class Inline extends Adapter implements Publisher, Consumer
{
    private const string CONTEXT_KEY = '__utopia_inline__';

    /** @var callable[] */
    protected array $onWorkerStart = [];

    /** @var callable[] */
    protected array $onWorkerStop = [];

    /**
     * @var (callable(Message): void)|null
     */
    private $messageCallback = null;

    /**
     * @var (callable(Message): void)|null
     */
    private $successCallback = null;

    /**
     * @var (callable(?Message, \Throwable): void)|null
     */
    private $errorCallback = null;

    /**
     * @var array<string, true>
     */
    private array $queues = [];

    public function __construct(
        int $workerNum = 1,
        string $namespace = 'utopia-queue',
        Container $resources = new Container(),
    ) {
        // Factory so multi-queue Server::start() does not treat this as a
        // shared receive connection. Receive is never used; enqueue drives work.
        parent::__construct(
            fn(): Consumer => $this,
            $workerNum,
            $namespace,
            $resources,
        );
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
        $this->stopped = true;

        foreach ($this->onWorkerStop as $callback) {
            $callback('0');
        }

        return $this;
    }

    public function workerStart(callable $callback): self
    {
        $this->onWorkerStart[] = $callback;

        return $this;
    }

    public function workerStop(callable $callback): self
    {
        $this->onWorkerStop[] = $callback;

        return $this;
    }

    /**
     * Store the Server callbacks and return. There is nothing to poll.
     *
     * @param array<int, array{queue: Queue, maxCoroutines: int, consumer?: Consumer}> $queues
     */
    #[\Override]
    public function consume(
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        array $queues,
    ): void {
        if ($queues === []) {
            throw new \LogicException('At least one queue is required');
        }

        $this->messageCallback = $messageCallback;
        $this->successCallback = $successCallback;
        $this->errorCallback = $errorCallback;
        $this->queues = [];

        foreach ($queues as $spec) {
            $this->queues[$spec['queue']->name] = true;
        }
    }

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        unset($priority);

        if ($this->messageCallback === null || $this->successCallback === null || $this->errorCallback === null) {
            throw new \LogicException('Inline adapter must start() before enqueue()');
        }

        if (!isset($this->queues[$queue->name])) {
            return true;
        }

        $message = new Message([
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ]);

        $this->queue = $queue;
        $previous = $this->swapContext(new Container($this->resources()));

        try {
            $this->processFrom(
                $message,
                $this->messageCallback,
                $this->successCallback,
                $this->errorCallback,
                $queue,
                $this,
            );
        } finally {
            $this->swapContext($previous);
        }

        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        unset($queue, $limit);
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        unset($queue, $failedJobs);

        return 0;
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        unset($queue, $timeout);

        return null;
    }

    public function commit(Queue $queue, Message $message): void
    {
        unset($queue, $message);
    }

    public function reject(Queue $queue, Message $message): void
    {
        unset($queue, $message);
    }

    public function close(): void {}

    #[\Override]
    public function context(): Container
    {
        $current = $this->currentContext();

        return $current ?? parent::context();
    }

    /**
     * Isolate each job (and nested enqueue) from its caller. Concurrent
     * Swoole requests share one adapter, so the stack lives on the coroutine
     * when one is running.
     */
    private function swapContext(?Container $next): ?Container
    {
        $previous = $this->currentContext();
        $this->storeContext($next);

        return $previous;
    }

    private function currentContext(): ?Container
    {
        if ($this->inCoroutine()) {
            $stored = \Swoole\Coroutine::getContext()[self::CONTEXT_KEY] ?? null;

            return $stored instanceof Container ? $stored : null;
        }

        return $this->context;
    }

    private function storeContext(?Container $context): void
    {
        if ($this->inCoroutine()) {
            \Swoole\Coroutine::getContext()[self::CONTEXT_KEY] = $context;

            return;
        }

        $this->context = $context;
    }

    private function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() !== -1;
    }
}
