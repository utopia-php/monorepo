<?php

namespace Utopia\CircuitBreaker;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\UpDownCounter;

class CircuitBreaker
{
    private const STATE_FIELD = 'state';
    private const FAILURES_FIELD = 'failures';
    private const SUCCESSES_FIELD = 'successes';
    private const OPENED_AT_FIELD = 'opened_at';

    private CircuitState $state = CircuitState::CLOSED;
    private int $failures = 0;
    private int $successes = 0;
    private ?int $openedAt = null;
    private ?Counter $calls = null;
    private ?Counter $callbackFailures = null;
    private ?Counter $fallbacks = null;
    private ?Counter $transitions = null;
    private ?UpDownCounter $activeCalls = null;
    private ?Gauge $stateGauge = null;
    private ?Gauge $failuresGauge = null;
    private ?Gauge $successesGauge = null;
    private ?Gauge $eventTimestamp = null;

    public function __construct(
        private int $threshold = 3,
        private int $timeout = 30,
        private int $successThreshold = 2,
        private ?Adapter $cache = null,
        private string $key = 'default',
        ?Telemetry $telemetry = null,
        private string $metricPrefix = ''
    ) {
        if ($this->cache !== null && $this->key === '') {
            throw new \InvalidArgumentException('Key must not be empty when a cache adapter is configured.');
        }

        if ($telemetry !== null) {
            $this->setTelemetry($telemetry);
        }
        $this->syncFromCache();
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->calls = $telemetry->createCounter($this->metricName('breaker.calls'), '{call}');
        $this->callbackFailures = $telemetry->createCounter($this->metricName('breaker.callback_failures'), '{failure}');
        $this->fallbacks = $telemetry->createCounter($this->metricName('breaker.fallbacks'), '{fallback}');
        $this->transitions = $telemetry->createCounter($this->metricName('breaker.transitions'), '{transition}');
        $this->activeCalls = $telemetry->createUpDownCounter($this->metricName('breaker.active_calls'), '{call}');
        $this->stateGauge = $telemetry->createGauge($this->metricName('breaker.state'));
        $this->failuresGauge = $telemetry->createGauge($this->metricName('breaker.failures'), '{failure}');
        $this->successesGauge = $telemetry->createGauge($this->metricName('breaker.successes'), '{success}');
        $this->eventTimestamp = $telemetry->createGauge($this->metricName('breaker.event.timestamp'), 's');
    }

    public function call(callable $open, callable $close, ?callable $halfOpen = null): mixed
    {
        $initialState = $this->state;
        $outcome = 'unknown';
        $exceptionType = null;
        $activeAttributes = null;

        try {
            $this->updateState();
            $initialState = $this->state;
            $activeAttributes = $this->telemetryAttributes(['circuit_breaker.state' => $initialState->value]);
            $this->activeCalls?->add(1, $activeAttributes);

            if ($this->state === CircuitState::OPEN) {
                $outcome = 'short_circuit';
                $this->fallbacks?->add(1, $this->telemetryAttributes([
                    'circuit_breaker.reason' => 'open',
                    'circuit_breaker.state' => $this->state->value,
                ]));

                return $open();
            }

            // Determine which callback to use
            $callback = ($this->state === CircuitState::HALF_OPEN && $halfOpen !== null)
                ? $halfOpen
                : $close;

            try {
                $result = $callback();
                $this->onSuccess();
                $outcome = 'success';
                return $result;
            } catch (\Throwable $e) {
                $exceptionType = $e::class;
                $this->callbackFailures?->add(1, $this->telemetryAttributes([
                    'exception.type' => $exceptionType,
                    'circuit_breaker.state' => $this->state->value,
                ]));
                $this->onFailure();
                $this->fallbacks?->add(1, $this->telemetryAttributes([
                    'circuit_breaker.reason' => 'failure',
                    'circuit_breaker.state' => $this->state->value,
                ]));
                $outcome = 'fallback';
                return $open();
            }
        } catch (\Throwable $e) {
            $exceptionType = $exceptionType ?? $e::class;
            $outcome = $outcome === 'unknown' ? 'exception' : $outcome . '_exception';
            throw $e;
        } finally {
            $attributes = $this->telemetryAttributes([
                'circuit_breaker.initial_state' => $initialState->value,
                'circuit_breaker.state' => $this->state->value,
                'circuit_breaker.outcome' => $outcome,
            ]);

            if ($exceptionType !== null) {
                $attributes['exception.type'] = $exceptionType;
            }

            $this->calls?->add(1, $attributes);
            if ($initialState === CircuitState::HALF_OPEN) {
                $this->recordEvent('probe', 'probe: ' . $outcome, [
                    'circuit_breaker.outcome' => $outcome,
                ]);
            }
            $this->recordState();
            if ($activeAttributes !== null) {
                $this->activeCalls?->add(-1, $activeAttributes);
            }
        }
    }

    private function updateState(): void
    {
        $this->syncFromCache();

        if ($this->state === CircuitState::OPEN && $this->hasTimedOut()) {
            $this->transitionToHalfOpen();
        }
    }

    private function onSuccess(): void
    {
        if ($this->state === CircuitState::HALF_OPEN) {
            $successes = $this->incrementSuccesses();

            if ($successes >= $this->successThreshold) {
                $this->transitionToClosed();
            }
        } elseif ($this->state === CircuitState::CLOSED) {
            if ($this->failures !== 0) {
                $this->setFailures(0);
            }
        }
    }

    private function onFailure(): void
    {
        $failures = $this->incrementFailures();

        if ($this->state === CircuitState::HALF_OPEN) {
            // Immediately reopen on failure in half-open state
            $this->transitionToOpen();
        } elseif ($failures >= $this->threshold) {
            $this->transitionToOpen();
        }
    }

    private function hasTimedOut(): bool
    {
        return $this->openedAt !== null && (time() - $this->openedAt) >= $this->timeout;
    }

    private function transitionToOpen(): void
    {
        $from = $this->state;
        $this->setOpenedAt(time());
        $this->setSuccesses(0);
        $this->setState(CircuitState::OPEN);
        $this->recordTransition($from, CircuitState::OPEN);
    }

    private function transitionToHalfOpen(): void
    {
        $from = $this->state;
        $this->setFailures(0);
        $this->setSuccesses(0);
        $this->setState(CircuitState::HALF_OPEN);
        $this->recordTransition($from, CircuitState::HALF_OPEN);
    }

    private function transitionToClosed(): void
    {
        $from = $this->state;
        $this->setFailures(0);
        $this->setSuccesses(0);
        $this->setOpenedAt(null);
        $this->setState(CircuitState::CLOSED);
        $this->recordTransition($from, CircuitState::CLOSED);
    }

    private function syncFromCache(): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->state = $this->loadState();
        $this->failures = $this->loadInteger(self::FAILURES_FIELD);
        $this->successes = $this->loadInteger(self::SUCCESSES_FIELD);
        $this->openedAt = $this->loadNullableInteger(self::OPENED_AT_FIELD);
    }

    private function setState(CircuitState $state): void
    {
        $this->state = $state;
        $this->cache?->set($this->cacheField(self::STATE_FIELD), $state->value);
    }

    private function setFailures(int $failures): void
    {
        $this->failures = $failures;
        $this->cache?->set($this->cacheField(self::FAILURES_FIELD), $failures);
    }

    private function incrementFailures(): int
    {
        if ($this->cache === null) {
            return ++$this->failures;
        }

        return $this->failures = $this->cache->increment($this->cacheField(self::FAILURES_FIELD));
    }

    private function setSuccesses(int $successes): void
    {
        $this->successes = $successes;
        $this->cache?->set($this->cacheField(self::SUCCESSES_FIELD), $successes);
    }

    private function incrementSuccesses(): int
    {
        if ($this->cache === null) {
            return ++$this->successes;
        }

        return $this->successes = $this->cache->increment($this->cacheField(self::SUCCESSES_FIELD));
    }

    private function setOpenedAt(?int $openedAt): void
    {
        $this->openedAt = $openedAt;

        if ($this->cache === null) {
            return;
        }

        $field = $this->cacheField(self::OPENED_AT_FIELD);
        if ($openedAt === null) {
            $this->cache->delete($field);
            return;
        }

        $this->cache->set($field, $openedAt);
    }

    private function loadState(): CircuitState
    {
        if ($this->cache === null) {
            return $this->state;
        }

        $value = $this->cache->get($this->cacheField(self::STATE_FIELD));
        if (!is_string($value)) {
            return CircuitState::CLOSED;
        }

        return CircuitState::tryFrom($value) ?? CircuitState::CLOSED;
    }

    private function loadInteger(string $field): int
    {
        if ($this->cache === null) {
            return 0;
        }

        $value = $this->cache->get($this->cacheField($field));

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function loadNullableInteger(string $field): ?int
    {
        if ($this->cache === null) {
            return null;
        }

        $value = $this->cache->get($this->cacheField($field));

        return is_numeric($value) ? (int) $value : null;
    }

    private function cacheField(string $field): string
    {
        return $this->key . ':' . $field;
    }

    private function metricName(string $name): string
    {
        $prefix = trim($this->metricPrefix, '.');

        return $prefix === '' ? $name : $prefix . '.' . $name;
    }

    /**
     * @param array<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     * @return array<non-empty-string, array<mixed>|bool|float|int|string|null>
     */
    private function telemetryAttributes(array $attributes = []): array
    {
        return ['circuit_breaker.name' => $this->key] + $attributes;
    }

    private function recordTransition(CircuitState $from, CircuitState $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->transitions?->add(1, $this->telemetryAttributes([
            'circuit_breaker.from_state' => $from->value,
            'circuit_breaker.to_state' => $to->value,
        ]));
        $this->recordEvent('transition', $from->value . ' -> ' . $to->value, [
            'circuit_breaker.from_state' => $from->value,
            'circuit_breaker.to_state' => $to->value,
        ]);
    }

    /**
     * @param array<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     */
    private function recordEvent(string $type, string $name, array $attributes = []): void
    {
        $this->eventTimestamp?->record(microtime(true), $this->telemetryAttributes([
            'circuit_breaker.event' => $type,
            'circuit_breaker.event_name' => $name,
        ] + $attributes));
    }

    private function recordState(): void
    {
        $attributes = $this->telemetryAttributes();
        $this->stateGauge?->record($this->stateValue(), $attributes);
        $this->failuresGauge?->record($this->failures, $attributes);
        $this->successesGauge?->record($this->successes, $attributes);
    }

    private function stateValue(): int
    {
        return match ($this->state) {
            CircuitState::CLOSED => 0,
            CircuitState::OPEN => 1,
            CircuitState::HALF_OPEN => 2,
        };
    }

    public function getState(): CircuitState
    {
        $this->updateState();
        return $this->state;
    }

    public function getFailureCount(): int
    {
        $this->syncFromCache();

        return $this->failures;
    }

    public function getSuccessCount(): int
    {
        $this->syncFromCache();

        return $this->successes;
    }

    public function isOpen(): bool
    {
        return $this->getState() === CircuitState::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::CLOSED;
    }

    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HALF_OPEN;
    }

    /**
     * Force the breaker into the open state. Idempotent: re-tripping refreshes
     * openedAt and re-emits gauges, but does not record a transition.
     */
    public function trip(): void
    {
        $this->syncFromCache();
        $this->transitionToOpen();
        $this->recordState();
    }
}
