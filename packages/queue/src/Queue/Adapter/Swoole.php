<?php

namespace Utopia\Queue\Adapter;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Process;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Consumer\Recoverable;
use Utopia\Queue\Message;
use Utopia\Queue\Option\Reliable;

class Swoole extends Adapter
{
    protected const string CONTEXT_KEY = '__utopia__';

    /** @var Process[] */
    protected array $workers = [];

    /** @var callable[] */
    protected array $onWorkerStart = [];

    /** @var callable[] */
    protected array $onWorkerStop = [];

    protected int $maxCoroutines;

    public function __construct(
        Consumer $consumer,
        int $workerNum,
        string $queue,
        string $namespace = 'utopia-queue',
        int $maxCoroutines = 1,
        Container $resources = new Container(),
        ?Reliable $reliable = null,
    ) {
        parent::__construct($consumer, $workerNum, $queue, $namespace, $resources, $reliable);
        $this->maxCoroutines = max(1, $maxCoroutines);
    }

    public function start(): self
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            $this->spawnWorker($i);
        }

        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

        Coroutine\run(function (): void {
            Process::signal(SIGTERM, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
            Process::signal(SIGINT, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
            Process::signal(SIGCHLD, fn() => $this->reap());

            while (\count($this->workers) > 0) {
                Coroutine::sleep(1);
            }
        });

        return $this;
    }

    protected function spawnWorker(int $workerId): void
    {
        $process = new Process(function () use ($workerId): void {
            Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

            Coroutine\run(function () use ($workerId): void {
                Process::signal(SIGTERM, function (): void {
                    $this->stopped = true;
                    $this->consumer->close();
                });

                foreach ($this->onWorkerStart as $callback) {
                    $callback((string) $workerId);
                }

                foreach ($this->onWorkerStop as $callback) {
                    $callback((string) $workerId);
                }
            });
        }, false, 0, false);

        $pid = $process->start();
        $this->workers[$pid] = $process;
    }

    /**
     * Receive on one loop, process each message on its own coroutine. The
     * channel caps concurrency at $maxCoroutines: push() blocks the loop while
     * the pool is full.
     */
    #[\Override]
    public function consume(callable $messageCallback, callable $successCallback, callable $errorCallback): void
    {
        if ($this->queue->reliable instanceof Reliable) {
            if (!$this->consumer instanceof Recoverable) {
                throw new \LogicException('Reliable Swoole queues require a recoverable consumer.');
            }

            $this->consumeReliable($messageCallback, $successCallback, $errorCallback, $this->consumer);

            return;
        }

        $this->consumeLegacy($messageCallback, $successCallback, $errorCallback);
    }

    private function consumeLegacy(callable $messageCallback, callable $successCallback, callable $errorCallback): void
    {
        $this->stopped = false;
        $slots = new Channel($this->maxCoroutines);
        $waitGroup = new WaitGroup();

        while (!$this->isStopped()) {
            $message = $this->consumer->receive($this->queue, static::RECEIVE_TIMEOUT);

            if (!$message instanceof \Utopia\Queue\Message) {
                continue;
            }

            $slots->push(true);
            $waitGroup->add();

            Coroutine::create(function () use ($message, $messageCallback, $successCallback, $errorCallback, $slots, $waitGroup): void {
                try {
                    $this->process($message, $messageCallback, $successCallback, $errorCallback);
                } catch (\Throwable $error) {
                    // process() is total; net for a stray throw so it isn't lost
                    error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                } finally {
                    $waitGroup->done();
                    $slots->pop();
                }
            });
        }

        $waitGroup->wait();
    }

    private function consumeReliable(
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Recoverable $recoverable,
    ): void {
        $reliable = $this->queue->reliable
            ?? throw new \LogicException('Reliable configuration is missing.');
        $this->stopped = false;
        $slots = new Channel($this->maxCoroutines);
        $handlers = new WaitGroup();
        $recoveryDone = new Channel(1);
        $recovery = new WaitGroup(1);

        Coroutine::create(function () use ($recoverable, $reliable, $recoveryDone, $recovery): void {
            try {
                while ($recoveryDone->pop($reliable->scan) === false) {
                    try {
                        do {
                            $claims = $recoverable->expired($this->queue, $reliable->batch);
                            foreach ($claims as $claim) {
                                $recoverable->reclaim($this->queue, $claim);
                            }
                            if (\count($claims) === $reliable->batch) {
                                Coroutine::sleep(0.001);
                            }
                        } while (\count($claims) === $reliable->batch && !$this->isStopped());
                    } catch (\Throwable $error) {
                        error_log('Queue recovery failed: ' . $error->getMessage());
                    }
                }
            } finally {
                $recovery->done();
            }
        });

        try {
            while (!$this->isStopped()) {
                $slots->push(true);
                if ($this->isStopped()) {
                    $slots->pop();
                    break;
                }

                try {
                    $message = $this->consumer->receive($this->queue, static::RECEIVE_TIMEOUT);
                } catch (\Throwable $error) {
                    $slots->pop();
                    throw $error;
                }
                if (!$message instanceof Message) {
                    $slots->pop();
                    continue;
                }

                $handlers->add();
                Coroutine::create(function () use (
                    $message,
                    $messageCallback,
                    $successCallback,
                    $errorCallback,
                    $recoverable,
                    $reliable,
                    $slots,
                    $handlers,
                ): void {
                    $heartbeatDone = new Channel(1);
                    $heartbeat = new WaitGroup(1);
                    Coroutine::create(function () use ($message, $recoverable, $reliable, $heartbeatDone, $heartbeat): void {
                        try {
                            while ($heartbeatDone->pop($reliable->heartbeat) === false) {
                                if (!$recoverable->extend($this->queue, $message)) {
                                    error_log("Queue lease was lost for message {$message->getPid()}.");
                                    return;
                                }
                            }
                        } catch (\Throwable $error) {
                            error_log("Queue heartbeat failed for message {$message->getPid()}: {$error->getMessage()}");
                        } finally {
                            $heartbeat->done();
                        }
                    });

                    try {
                        $this->process($message, $messageCallback, $successCallback, $errorCallback);
                    } catch (\Throwable $error) {
                        error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                    } finally {
                        $heartbeatDone->push(true);
                        $heartbeat->wait();
                        $handlers->done();
                        $slots->pop();
                    }
                });
            }
        } finally {
            $handlers->wait();
            $recoveryDone->push(true);
            $recovery->wait();
        }
    }

    #[\Override]
    public function context(): Container
    {
        // Each message runs in its own coroutine, so the container is created
        // lazily per coroutine and stays isolated across concurrent handlers.
        if (Coroutine::getCid() !== -1) {
            return Coroutine::getContext()[self::CONTEXT_KEY] ??= new Container($this->resources());
        }

        return $this->resources();
    }

    protected function reap(): void
    {
        while (($ret = Process::wait(false)) !== false) {
            unset($this->workers[$ret['pid']]);
        }
    }

    public function stop(): self
    {
        $this->stopped = true;

        foreach (array_keys($this->workers) as $pid) {
            Process::kill($pid, SIGTERM);
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
}
