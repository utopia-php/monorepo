<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

class CircuitBreaker implements Adapter, Feature\Leasable, Feature\Telemetry
{
    /**
     * Whether this open episode has already shown the cache cannot be written.
     *
     * A repair attempt is worth one timeout to find out, and worth nothing after
     * that: if the adapter is not answering at all, retrying the write on every
     * request would add its timeout to every request, which is the cost an open
     * circuit exists to avoid. One attempt per episode keeps the repair where it
     * pays — a cache that is already well again, behind a circuit that has not
     * closed yet — and drops it where it does not.
     */
    private bool $repairUnavailable = false;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly UtopiaCircuitBreaker $breaker,
    ) {}

    /**
     * Forward method calls to the internal adapter through the circuit breaker.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param  array<mixed>  $args
     */
    public function delegate(string $method, array $args, mixed $fallback): mixed
    {
        return $this->breaker->call(
            open: fn(): mixed => $fallback,
            close: fn(): mixed => $this->adapter->{$method}(...$args),
        );
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args(), false);
    }

    /**
     * Route a write, which an open circuit must not refuse outright.
     *
     * Shedding reads while the dependency is sick is what a breaker is for: the
     * read would fail anyway, so refusing it early costs nothing. A write is not
     * the same thing — it is the repair. Refusing it holds the miss rate at 100%
     * for as long as the circuit stays open, so the traffic the breaker diverted
     * keeps arriving at whatever it was diverted to well after the cache itself is
     * healthy. The cache cannot warm up while it is forbidden to remember
     * anything.
     *
     * While open the write bypasses the breaker rather than reporting to it. The
     * verdict is already made, so one more data point cannot change it, and what
     * decides when the circuit closes should be the probes half-open schedules,
     * not repair traffic arriving at whatever rate the fallback path happens to
     * generate.
     *
     * @param  array<mixed>  $args
     */
    private function repair(string $method, array $args, mixed $fallback): mixed
    {
        if (! $this->breaker->isOpen()) {
            // Not open: the write reports to the breaker like any other call, so a
            // failing cache can still open the circuit. This also clears the guard
            // below, scoping it to a single open episode.
            $this->repairUnavailable = false;

            return $this->delegate($method, $args, $fallback);
        }

        if ($this->repairUnavailable) {
            return $fallback;
        }

        try {
            return $this->adapter->{$method}(...$args);
        } catch (\Throwable) {
            $this->repairUnavailable = true;

            return $fallback;
        }
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        /** @var bool|string|array<int|string, mixed> $result */
        $result = $this->repair(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function getGeneration(string $key): string
    {
        if (! $this->adapter instanceof Feature\Leasable) {
            return '0';
        }

        /** @var string $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), '0');

        return $result;
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        if (! $this->adapter instanceof Feature\Leasable) {
            return $this->save($key, $data, $hash);
        }

        /** @var bool|string|array<int|string, mixed> $result */
        $result = $this->repair(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->repair(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function list(string $key): array
    {
        /** @var string[] $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), []);

        return $result;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function flush(): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function ping(): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function getSize(): int
    {
        /** @var int $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), 0);

        return $result;
    }

    public function getName(?string $key = null): string
    {
        try {
            return $this->adapter->getName($key);
        } catch (\Throwable) {
            return 'circuit-breaker';
        }
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->breaker->setTelemetry($telemetry);

        if ($this->adapter instanceof Feature\Telemetry) {
            $this->adapter->setTelemetry($telemetry);
        }
    }
}
