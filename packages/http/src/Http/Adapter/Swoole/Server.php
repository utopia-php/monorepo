<?php

namespace Utopia\Http\Adapter\Swoole;

use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleServer;
use Swoole\Timer;
use Utopia\DI\Container;
use Utopia\Http\Adapter;
use Utopia\Telemetry\Adapter as Telemetry;

class Server extends Adapter
{
    protected SwooleServer $server;
    protected const string CONTEXT_KEY = '__utopia__';

    /**
     * Keys in Server::stats() scoped to the calling worker process rather than
     * the whole server, so they must be emitted from every worker for a
     * sum() across instances to be correct.
     *
     * "coroutine_peek_num" is the upstream Swoole key, typo and all
     * (Co::stats() exposes the corrected "coroutine_peak_num"); this matches
     * Server::stats() so we keep the typo.
     *
     * @var list<string>
     */
    public const array PER_WORKER_STATS_KEYS = [
        'worker_request_count',
        'worker_response_count',
        'worker_dispatch_count',
        'worker_concurrency',
        'coroutine_num',
        'coroutine_peek_num',
    ];

    /**
     * Request context for non-coroutine modes, where a worker handles
     * one request at a time and there is no coroutine context to hang it on.
     */
    protected ?Container $context = null;

    /**
     * @param  Mode|array<string, mixed>  $settings
     */
    public function __construct(
        string $host,
        ?string $port = null,
        Mode|array $settings = [],
        int $mode = SWOOLE_PROCESS,
        protected Container $resources = new Container(),
    ) {
        $this->server = new SwooleServer($host, (int) $port, $mode);
        $this->server->set($settings instanceof Mode ? $settings->settings() : $settings);
    }

    public function onRequest(callable $callback): void
    {
        $this->server->on('request', function (SwooleRequest $request, SwooleResponse $response) use ($callback) {
            $context = new Container($this->resources);
            $context->set('swooleRequest', fn() => $request);
            $context->set('swooleResponse', fn() => $response);

            $cid = Coroutine::getCid();
            if ($cid !== -1) {
                Coroutine::getContext()[self::CONTEXT_KEY] = $context;
            } else {
                $this->context = $context;
            }

            try {
                \call_user_func($callback, new Request($request), new Response($response));
            } finally {
                // Coroutine mode discards its context slot when the coroutine
                // ends; the non-coroutine slot is shared across requests, so
                // clear it to keep the "no context between requests" invariant.
                if ($cid === -1) {
                    $this->context = null;
                }
            }
        });
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        if (Coroutine::getCid() !== -1) {
            return Coroutine::getContext()[self::CONTEXT_KEY] ?? $this->resources;
        }

        return $this->context ?? $this->resources;
    }

    public function getServer(): SwooleServer
    {
        return $this->server;
    }

    /**
     * Register Swoole runtime telemetry for the calling worker.
     *
     * Call this from inside a worker-start handler (Swoole allows only one
     * handler per event, so the application owns that hook and forwards the
     * per-worker telemetry adapter and worker id here). It schedules timers
     * that emit Swoole's own server/coroutine/runtime metrics under the
     * `swoole.*` namespace:
     *
     *  - per-worker stats from {@see self::PER_WORKER_STATS_KEYS} (every worker)
     *  - global server stats + the reactor/coroutine config ceilings (worker 0)
     *  - coroutine creations, AIO backlog, reactor events, signal listeners,
     *    active timers, memory, and event-loop scheduler lag
     *
     * Instruments are created once and reused on every tick. Timers are
     * cleared by Swoole on worker exit (or call Timer::clearAll() yourself).
     */
    public function collectTelemetry(Telemetry $telemetry, int $workerId, int $intervalMs = 10_000): void
    {
        $server = $this->server;

        $gauges = [];
        $gauge = function (string $name) use ($telemetry, &$gauges) {
            return $gauges[$name] ??= $telemetry->createGauge($name);
        };

        // Global server stats are master-tracked, so emit them from worker 0
        // only to avoid every worker reporting the same numbers.
        if ($workerId === 0) {
            // Server::setting only holds values passed to the constructor; when
            // a key is absent Swoole uses its built-in default.
            $settings = $server->setting ?? [];
            $reactorThreads = $gauge('swoole.reactor.threads');
            $coroutineMax = $gauge('swoole.coroutine.max');

            Timer::tick($intervalMs, function () use ($server, $gauge, $settings, $reactorThreads, $coroutineMax) {
                foreach ($server->stats() as $key => $value) {
                    if (\in_array($key, self::PER_WORKER_STATS_KEYS, true)) {
                        continue;
                    }
                    if (!is_numeric($value)) {
                        continue;
                    }
                    $gauge(self::telemetryName($key))->record($value);
                }
                $reactorThreads->record($settings['reactor_num'] ?? swoole_cpu_num());
                $coroutineMax->record($settings['max_coroutine'] ?? 100_000);
            });
        }

        // coroutine_last_cid is a thread-local counter incremented on every
        // Coroutine::create(); emit the delta as a monotonic Counter so rate()
        // handles worker restarts instead of seeing a negative spike.
        $coroutineCreated = $telemetry->createCounter(
            'swoole.coroutine.created',
            'coroutines',
            'Total coroutines created in this worker process.',
        );
        $lastCid = 0;

        $perWorker = [];
        foreach (self::PER_WORKER_STATS_KEYS as $key) {
            $perWorker[$key] = $gauge(self::telemetryName($key));
        }
        $aioTasksPending = $gauge('swoole.aio.tasks_pending'); // file-I/O thread-pool backlog
        $aioWorkers = $gauge('swoole.aio.workers');
        $reactorEvents = $gauge('swoole.reactor.events'); // FDs in reactor, not a backlog
        $signalListeners = $gauge('swoole.signal_listeners');
        $timersActive = $gauge('swoole.timers.active');
        $memoryUsage = $gauge('swoole.memory.usage_bytes');
        $memoryPeak = $gauge('swoole.memory.peak_bytes');
        $schedulerLag = $gauge('swoole.scheduler.lag_ms');

        // Per-worker stats: emit from every worker so a sum across instances is
        // accurate.
        Timer::tick($intervalMs, function () use (
            $server,
            $workerId,
            $coroutineCreated,
            &$lastCid,
            $perWorker,
            $aioTasksPending,
            $aioWorkers,
            $reactorEvents,
            $signalListeners,
            $timersActive,
            $memoryUsage,
            $memoryPeak,
            $schedulerLag,
        ) {
            $stats = $server->stats();
            foreach ($perWorker as $key => $instrument) {
                if (isset($stats[$key])) {
                    $instrument->record($stats[$key]);
                }
            }

            $co = Coroutine::stats();
            $aioTasksPending->record($co['aio_task_num'] ?? 0);
            $aioWorkers->record($co['aio_worker_num'] ?? 0);
            $reactorEvents->record($co['event_num'] ?? 0);
            $signalListeners->record($co['signal_listener_num'] ?? 0);

            $cid = $co['coroutine_last_cid'] ?? 0;
            if ($cid > $lastCid) {
                $coroutineCreated->add($cid - $lastCid);
            }
            $lastCid = $cid;

            $timersActive->record(Timer::stats()['num'] ?? 0);
            // real_usage=false reports the in-use script heap, not the OS pool
            // (which grows in slabs and rarely shrinks), revealing per-request churn.
            $memoryUsage->record(memory_get_usage(false));
            $memoryPeak->record(memory_get_peak_usage(false));

            // Co::sleep(10ms) should take ~10ms; any extra is how long the event
            // loop was blocked. Needs a coroutine, so skip in non-coroutine mode.
            if (Coroutine::getCid() !== -1) {
                $startNs = hrtime(true);
                Coroutine::sleep(0.01);
                $lagMs = max(0.0, (hrtime(true) - $startNs) / 1_000_000 - 10);
                $schedulerLag->record($lagMs, ['worker_id' => (string) $workerId]);
            }
        });
    }

    /**
     * Map a Swoole stats() key to its `swoole.*` telemetry metric name,
     * collapsing the `_count`/`_num` suffixes to a `.count` segment.
     */
    public static function telemetryName(string $statKey): string
    {
        return 'swoole.' . str_replace(['_count', '_num'], '.count', $statKey);
    }

    public function onStart(callable $callback): void
    {
        $this->server->on('start', function () use ($callback) {
            go(function () use ($callback) {
                \call_user_func($callback, $this);
            });
        });
    }

    public function start(): void
    {
        $this->server->start();
    }
}
