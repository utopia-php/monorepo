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
     * Worker-start callbacks, multiplexed onto Swoole's single workerStart
     * handler so telemetry and application init can coexist.
     *
     * @var list<callable(int): void>
     */
    private array $workerStartCallbacks = [];

    private ?Telemetry $telemetry = null;

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

    public function onWorkerStart(callable $callback): void
    {
        if ($this->workerStartCallbacks === []) {
            $this->server->on('workerStart', function (SwooleServer $server, int $workerId): void {
                foreach ($this->workerStartCallbacks as $cb) {
                    $cb($workerId);
                }
            });
        }
        $this->workerStartCallbacks[] = $callback;
    }

    /**
     * Publish Swoole's own server/coroutine/runtime metrics through the given
     * telemetry adapter. Observable gauges are registered on each worker start
     * and read live state lazily, so the application's normal
     * `$telemetry->collect()` drives them — no extra timers. Metrics are emitted
     * under the `swoole.*` namespace:
     *
     *  - per-worker stats from {@see self::PER_WORKER_STATS_KEYS} (every worker)
     *  - global server stats + the reactor/coroutine config ceilings (worker 0)
     *  - coroutine creations, AIO backlog, reactor events, signal listeners,
     *    active timers, memory, and event-loop scheduler lag
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        // Wire the worker-start hook once; later calls just swap the adapter
        // the single registration reads at collection time.
        $register = $this->telemetry === null;
        $this->telemetry = $telemetry;
        if ($register) {
            $this->onWorkerStart(function (int $workerId): void {
                if ($this->telemetry !== null) {
                    $this->registerTelemetryGauges($this->telemetry, $workerId);
                }
            });
        }
    }

    private function registerTelemetryGauges(Telemetry $telemetry, int $workerId): void
    {
        $server = $this->server;
        // Server::setting only holds values passed to the constructor; absent
        // keys fall back to Swoole's built-in defaults.
        $settings = $server->setting ?? [];

        // Register an observable gauge whose value is read on each collect().
        // A null reading is skipped so absent keys don't emit a 0 series.
        $observe = function (string $name, callable $value) use ($telemetry): void {
            $telemetry->createObservableGauge($name)->observe(function (callable $observer) use ($value): void {
                $reading = $value();
                if ($reading !== null) {
                    $observer($reading, []);
                }
            });
        };

        // Per-worker stats: registered on every worker so a sum across
        // service.instance.id is accurate. max_coroutine is a per-worker ceiling
        // (PHPCoroutine::config is thread-local), so it pairs with coroutine_num.
        foreach (self::PER_WORKER_STATS_KEYS as $key) {
            $observe(self::telemetryName($key), fn() => $server->stats()[$key] ?? null);
        }
        $observe('swoole.coroutine.max', fn() => $settings['max_coroutine'] ?? 100_000);

        // Global server stats are master-tracked, so emit them from worker 0
        // only to avoid every worker reporting the same numbers. The key set is
        // stable once the server is running, so enumerate it here at registration.
        if ($workerId === 0) {
            foreach ($server->stats() as $key => $value) {
                if (\in_array($key, self::PER_WORKER_STATS_KEYS, true)) {
                    continue;
                }
                if (!is_numeric($value)) {
                    continue;
                }
                $observe(self::telemetryName($key), fn() => $server->stats()[$key] ?? null);
            }
            // reactor threads run in the master (SWOOLE_PROCESS mode), so this
            // ceiling is server-wide, not per-worker.
            $observe('swoole.reactor.threads', fn() => $settings['reactor_num'] ?? swoole_cpu_num());
        }

        // coroutine_last_cid is the cumulative count of coroutines created in
        // this worker; report it directly so the backend can rate() it.
        $observe('swoole.coroutine.created', fn() => Coroutine::stats()['coroutine_last_cid'] ?? 0);
        $observe('swoole.aio.tasks_pending', fn() => Coroutine::stats()['aio_task_num'] ?? 0);
        $observe('swoole.aio.workers', fn() => Coroutine::stats()['aio_worker_num'] ?? 0);
        $observe('swoole.reactor.events', fn() => Coroutine::stats()['event_num'] ?? 0);
        $observe('swoole.signal_listeners', fn() => Coroutine::stats()['signal_listener_num'] ?? 0);
        $observe('swoole.timers.active', fn() => Timer::stats()['num'] ?? 0);
        // real_usage=false reports the in-use script heap, not the OS pool (which
        // grows in slabs and rarely shrinks), revealing per-request churn.
        $observe('swoole.memory.usage_bytes', fn() => memory_get_usage(false));
        $observe('swoole.memory.peak_bytes', fn() => memory_get_peak_usage(false));

        // Co::sleep(10ms) should take ~10ms; any extra is how long the event loop
        // was blocked. Needs a coroutine, so it's skipped in non-coroutine mode.
        $telemetry->createObservableGauge('swoole.scheduler.lag_ms')->observe(function (callable $observer): void {
            if (Coroutine::getCid() === -1) {
                return;
            }
            $startNs = hrtime(true);
            Coroutine::sleep(0.01);
            $observer(max(0.0, (hrtime(true) - $startNs) / 1_000_000 - 10), []);
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
