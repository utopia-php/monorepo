<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Utopia\Queue\Publisher\Asynchronous;
use Utopia\Queue\Publisher\BackpressureException;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * Wraps a synchronous publisher and adds asynchronous, background dispatch on
 * top of a Swoole coroutine — so it satisfies both the Synchronous and
 * Asynchronous contracts.
 *
 * enqueue() pushes the publish onto a bounded channel and returns; one or more
 * reader coroutines loop over the channel and delegate each dispatch to the
 * wrapped synchronous publisher. The channel capacity is the back-pressure
 * bound — once it fills, enqueue() blocks the producing coroutine until a reader
 * drains a slot, so a slow broker throttles producers instead of piling up
 * unbounded work. $timeout caps that wait: enqueue() throws BackpressureException
 * if no slot frees within it; -1 (the default) waits indefinitely.
 *
 * $coroutines sets how many reader coroutines dispatch concurrently. Values above
 * 1 only make sense when the wrapped publisher tolerates concurrent use across
 * coroutines: a single-connection broker (e.g. a bare Redis) must not be shared
 * — wrap a connection Pool instead, so each dispatch leases its own connection.
 * More than one coroutine also gives up FIFO dispatch order.
 *
 * Telemetry (no-op by default) reports the buffer depth as an observable gauge.
 * Dispatch counts and failures aren't metered here — the wrapped synchronous
 * publisher already sees every publish and can report those itself.
 *
 * publish() bypasses the channel and delegates synchronously.
 */
class Background implements Synchronous, Asynchronous
{
    private readonly Channel $channel;

    private readonly WaitGroup $waitGroup;

    private readonly int $coroutines;

    private bool $started = false;

    public function __construct(
        private readonly Synchronous $publisher,
        int $capacity = 512,
        int $coroutines = 1,
        private readonly float $timeout = -1,
        Telemetry $telemetry = new NoTelemetry(),
    ) {
        $this->channel = new Channel(max(1, $capacity));
        $this->waitGroup = new WaitGroup();
        $this->coroutines = max(1, $coroutines);

        $telemetry->createObservableGauge(
            'messaging.publisher.buffer.depth',
            '{message}',
            'Publishes buffered awaiting background dispatch.',
        )->observe(function (callable $observe): void {
            $observe($this->channel->length(), []);
        });
    }

    /**
     * Spawn the reader coroutines that drain the channel into the wrapped
     * publisher. Call once from within a coroutine runtime; until then
     * enqueue() publishes synchronously.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        for ($i = 0; $i < $this->coroutines; $i++) {
            $this->waitGroup->add();

            Coroutine::create(function (): void {
                try {
                    while (($task = $this->channel->pop()) instanceof \Closure) {
                        $task();
                    }
                } finally {
                    $this->waitGroup->done();
                }
            });
        }
    }

    /**
     * Drain the channel and stop the readers, blocking until they have finished.
     * Messages already enqueued are published before the readers exit.
     */
    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        for ($i = 0; $i < $this->coroutines; $i++) {
            $this->channel->push(null); // one sentinel per reader; pop() returns non-Closure → loop ends
        }

        $this->waitGroup->wait();
        $this->started = false;
    }

    /**
     * Publish synchronously, blocking until the broker accepts the message.
     */
    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        return $this->publisher->publish($queue, $payload, $priority);
    }

    /**
     * Hand the publish to the background reader via the channel. Blocks when the
     * channel is full (back pressure), up to the configured timeout, then throws
     * BackpressureException if no slot frees in time. Falls back to a synchronous
     * publish when no reader loop is running.
     *
     * @throws BackpressureException when the buffer stays full past the timeout.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): void
    {
        if (!$this->started || Coroutine::getCid() === -1) {
            $this->publish($queue, $payload, $priority);

            return;
        }

        $accepted = $this->channel->push(function () use ($queue, $payload, $priority): void {
            try {
                $this->publisher->publish($queue, $payload, $priority);
            } catch (\Throwable $error) {
                // Fire-and-forget: no producer to surface to, so log and move on.
                error_log('Uncaught error while publishing queue message: ' . $error->getMessage());
            }
        }, $this->timeout);

        if ($accepted === false) {
            throw new BackpressureException('Publisher buffer full; enqueue timed out.');
        }
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        $this->publisher->retry($queue, $limit);
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->publisher->getQueueSize($queue, $failedJobs);
    }
}
