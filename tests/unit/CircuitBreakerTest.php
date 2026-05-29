<?php

namespace Utopia\Tests\unit;

use Utopia\CircuitBreaker\Adapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\Telemetry\UpDownCounter;

final class CircuitBreakerTest extends TestCase
{
    public function testUsesInMemoryStateByDefault(): void
    {
        $breaker = new CircuitBreaker(threshold: 2, timeout: 30, successThreshold: 1);

        $first = $breaker->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );
        $second = $breaker->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertSame('fallback', $first);
        self::assertSame('fallback', $second);
        self::assertSame(CircuitState::OPEN, $breaker->getState());
        self::assertSame(2, $breaker->getFailureCount());
    }

    public function testCachedStateIsSharedAcrossBreakerInstances(): void
    {
        $cache = $this->createArrayAdapter();
        $first = new CircuitBreaker(threshold: 2, timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api');
        $second = new CircuitBreaker(threshold: 2, timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api');

        $first->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );
        $first->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertTrue($second->isOpen());
        self::assertSame(2, $second->getFailureCount());

        $result = $second->call(
            open: static fn () => 'shared fallback',
            close: function (): void {
                self::fail('Closed callback should not run while the shared circuit is open.');
            }
        );

        self::assertSame('shared fallback', $result);
    }

    public function testClosedSuccessDoesNotWriteZeroFailuresWhenAlreadyZero(): void
    {
        $cache = new class () implements Adapter {
            /**
             * @var list<array{string, string, int|string|null}>
             */
            public array $writes = [];

            public function get(string $key): int|string|null
            {
                return null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->writes[] = ['set', $key, $value];
            }

            public function increment(string $key, int $by = 1): int
            {
                $this->writes[] = ['increment', $key, $by];

                return $by;
            }

            public function delete(string $key): void
            {
                $this->writes[] = ['delete', $key, null];
            }
        };
        $breaker = new CircuitBreaker(threshold: 1, timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api');

        self::assertSame('ok', $breaker->call(
            open: static fn () => 'fallback',
            close: static fn () => 'ok'
        ));

        self::assertSame([], $cache->writes);
    }

    public function testCachedTransitionsWriteStateLast(): void
    {
        $cache = new class () implements Adapter {
            /**
             * @var array<string, int|string>
             */
            private array $values = [];

            /**
             * @var list<array{string, string, int|string|null}>
             */
            public array $writes = [];

            public function get(string $key): int|string|null
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->writes[] = ['set', $key, $value];
                $this->values[$key] = $value;
            }

            public function increment(string $key, int $by = 1): int
            {
                $value = (int) ($this->values[$key] ?? 0);
                $value += $by;
                $this->writes[] = ['increment', $key, $by];
                $this->values[$key] = $value;

                return $value;
            }

            public function delete(string $key): void
            {
                $this->writes[] = ['delete', $key, null];
                unset($this->values[$key]);
            }
        };
        $breaker = new CircuitBreaker(threshold: 1, timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api');

        $breaker->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        $setWrites = array_values(array_filter(
            $cache->writes,
            static fn (array $write): bool => $write[0] === 'set'
        ));

        self::assertSame(['set', 'users-api:state', CircuitState::OPEN->value], $setWrites[array_key_last($setWrites)]);
    }

    public function testHalfOpenSuccessesCloseTheCircuit(): void
    {
        $breaker = new CircuitBreaker(threshold: 1, timeout: 0, successThreshold: 2);

        $breaker->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertSame('probe-1', $breaker->call(
            open: static fn () => 'fallback',
            close: static fn () => 'closed',
            halfOpen: static fn () => 'probe-1'
        ));
        self::assertTrue($breaker->isHalfOpen());
        self::assertSame(1, $breaker->getSuccessCount());

        self::assertSame('probe-2', $breaker->call(
            open: static fn () => 'fallback',
            close: static fn () => 'closed',
            halfOpen: static fn () => 'probe-2'
        ));

        self::assertTrue($breaker->isClosed());
        self::assertSame(0, $breaker->getFailureCount());
        self::assertSame(0, $breaker->getSuccessCount());
    }

    public function testRecordsTelemetryForCallsFallbacksAndTransitions(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(threshold: 1, timeout: 30, successThreshold: 1, telemetry: $telemetry);

        $result = $breaker->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertSame('fallback', $result);
        self::assertSame([1], $telemetry->counters['breaker.calls']->values);
        self::assertSame([1], $telemetry->counters['breaker.callback_failures']->values);
        self::assertSame([1], $telemetry->counters['breaker.fallbacks']->values);
        self::assertSame([1], $telemetry->counters['breaker.transitions']->values);
        self::assertSame([1, -1], $telemetry->upDownCounters['breaker.active_calls']->values);
        self::assertSame([1], $telemetry->gauges['breaker.state']->values);
        self::assertSame([1], $telemetry->gauges['breaker.failures']->values);
        self::assertSame([0], $telemetry->gauges['breaker.successes']->values);
        self::assertCount(1, $telemetry->gauges['breaker.event.timestamp']->values);
    }

    public function testPrefixesTelemetryMetricNames(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(threshold: 1, timeout: 30, successThreshold: 1, metricPrefix: '.edge.');
        $breaker->setTelemetry($telemetry);

        $result = $breaker->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertSame('fallback', $result);
        self::assertSame([1], $telemetry->counters['edge.breaker.calls']->values);
        self::assertSame([1], $telemetry->counters['edge.breaker.callback_failures']->values);
        self::assertSame([1], $telemetry->counters['edge.breaker.fallbacks']->values);
        self::assertSame([1], $telemetry->counters['edge.breaker.transitions']->values);
        self::assertSame([1, -1], $telemetry->upDownCounters['edge.breaker.active_calls']->values);
        self::assertSame([1], $telemetry->gauges['edge.breaker.state']->values);
        self::assertSame([1], $telemetry->gauges['edge.breaker.failures']->values);
        self::assertSame([0], $telemetry->gauges['edge.breaker.successes']->values);
        self::assertCount(1, $telemetry->gauges['edge.breaker.event.timestamp']->values);
        self::assertArrayNotHasKey('breaker.calls', $telemetry->counters);
    }

    public function testInspectionMethodsDoNotEmitTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        self::assertSame(CircuitState::CLOSED, $breaker->getState());
        self::assertSame(0, $breaker->getFailureCount());
        self::assertSame(0, $breaker->getSuccessCount());
        self::assertSame([], $telemetry->gauges['breaker.state']->values);
        self::assertSame([], $telemetry->gauges['breaker.failures']->values);
        self::assertSame([], $telemetry->gauges['breaker.successes']->values);
        self::assertSame([], $telemetry->counters['breaker.calls']->values);
        self::assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
    }

    public function testRareTelemetryInstrumentsAreCreatedOnFirstRecord(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        self::assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);

        $breaker->trip();

        self::assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        self::assertSame([1], $telemetry->counters['breaker.transitions']->values);
        self::assertCount(1, $telemetry->gauges['breaker.event.timestamp']->values);
    }

    public function testSuccessfulCallsDoNotCreateRareTelemetryInstruments(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        self::assertSame('ok', $breaker->call(
            open: static fn () => 'fallback',
            close: static fn () => 'ok',
        ));

        self::assertSame([1], $telemetry->counters['breaker.calls']->values);
        self::assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        self::assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
    }

    public function testActiveCallTelemetryUsesPostUpdateState(): void
    {
        $store = new ActiveCallAttributeStore();
        $telemetry = new ActiveCallTelemetry($store);
        $cache = new class () implements Adapter {
            /**
             * @var array<string, int|string>
             */
            private array $values = [
                'users-api:state' => 'open',
                'users-api:failures' => 1,
                'users-api:successes' => 0,
            ];

            public function __construct()
            {
                $this->values['users-api:opened_at'] = time() - 10;
            }

            public function get(string $key): int|string|null
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->values[$key] = $value;
            }

            public function increment(string $key, int $by = 1): int
            {
                $value = (int) ($this->values[$key] ?? 0);
                $value += $by;
                $this->values[$key] = $value;

                return $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
        $breaker = new CircuitBreaker(
            threshold: 1,
            timeout: 0,
            successThreshold: 1,
            cache: $cache,
            key: 'users-api',
            telemetry: $telemetry
        );

        $result = $breaker->call(
            open: static fn () => 'fallback',
            close: static fn () => 'closed',
            halfOpen: static fn () => 'probe'
        );

        self::assertSame('probe', $result);
        self::assertCount(2, $store->attributes);
        self::assertSame(CircuitState::HALF_OPEN->value, $store->attributes[0]['circuit_breaker.state']);
        self::assertSame(CircuitState::HALF_OPEN->value, $store->attributes[1]['circuit_breaker.state']);
    }

    public function testRejectsEmptyCacheKeyWhenCacheIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CircuitBreaker(cache: $this->createArrayAdapter(), key: '');
    }

    public function testTripTransitionsToOpen(): void
    {
        $breaker = new CircuitBreaker();

        self::assertSame(CircuitState::CLOSED, $breaker->getState());

        $breaker->trip();

        self::assertSame(CircuitState::OPEN, $breaker->getState());
        self::assertTrue($breaker->isOpen());
    }

    public function testTrippedBreakerShortCircuitsCalls(): void
    {
        $breaker = new CircuitBreaker(threshold: 100, timeout: 30, successThreshold: 1);
        $breaker->trip();

        $result = $breaker->call(
            open: static fn () => 'fallback',
            close: function (): void {
                self::fail('Closed callback should not run when the breaker has been tripped.');
            }
        );

        self::assertSame('fallback', $result);
        self::assertTrue($breaker->isOpen());
    }

    public function testTripIsIdempotent(): void
    {
        $breaker = new CircuitBreaker();

        $breaker->trip();
        $breaker->trip();
        $breaker->trip();

        self::assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testTripPersistsStateThroughCacheAdapter(): void
    {
        $cache = $this->createArrayAdapter();
        $first = new CircuitBreaker(cache: $cache, key: 'users-api');
        $first->trip();

        $second = new CircuitBreaker(cache: $cache, key: 'users-api');

        self::assertTrue($second->isOpen());
    }

    public function testTripEmitsTransitionTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $breaker->trip();

        self::assertSame([1], $telemetry->counters['breaker.transitions']->values);
        self::assertSame([1], $telemetry->gauges['breaker.state']->values);
    }

    private function createArrayAdapter(): Adapter
    {
        return new class () implements Adapter {
            /**
             * @var array<string, int|string>
             */
            private array $values = [];

            public function get(string $key): int|string|null
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->values[$key] = $value;
            }

            public function increment(string $key, int $by = 1): int
            {
                $value = (int) ($this->values[$key] ?? 0);
                $value += $by;
                $this->values[$key] = $value;

                return $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
    }
}

final class ActiveCallAttributeStore
{
    /**
     * @var list<array<non-empty-string, array<mixed>|bool|float|int|string|null>>
     */
    public array $attributes = [];
}

final class ActiveCallTelemetry extends TestTelemetry
{
    public function __construct(private ActiveCallAttributeStore $store)
    {
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): UpDownCounter {
        if ($name !== 'breaker.active_calls') {
            return parent::createUpDownCounter($name, $unit, $description, $advisory);
        }

        $counter = new class ($this->store) extends UpDownCounter {
            public function __construct(private ActiveCallAttributeStore $store)
            {
            }

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->store->attributes[] = iterator_to_array($attributes);
            }
        };
        $this->upDownCounters[$name] = $counter;

        return $counter;
    }
}
