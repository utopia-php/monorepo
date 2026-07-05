<?php

declare(strict_types=1);

namespace Utopia\Tests\unit;

use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\Adapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\Telemetry\UpDownCounter;

final class CircuitBreakerTest extends TestCase
{
    public function testUsesInMemoryStateByDefault(): void
    {
        $breaker = new CircuitBreaker(threshold: 2, timeout: 30, successThreshold: 1);

        $first = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );
        $second = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('fallback', $first);
        $this->assertSame('fallback', $second);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(2, $breaker->getFailureCount());
    }

    public function testCachedStateIsSharedAcrossBreakerInstances(): void
    {
        $cache = $this->createArrayAdapter();
        $first = new CircuitBreaker(threshold: 2, timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api');
        $second = new CircuitBreaker(threshold: 2, timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api');

        $first->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );
        $first->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertTrue($second->isOpen());
        $this->assertSame(2, $second->getFailureCount());

        $result = $second->call(
            open: static fn(): string => 'shared fallback',
            close: function (): never {
                self::fail('Closed callback should not run while the shared circuit is open.');
            },
        );

        $this->assertSame('shared fallback', $result);
    }

    public function testClosedSuccessDoesNotWriteZeroFailuresWhenAlreadyZero(): void
    {
        $cache = new class implements Adapter {
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

        $this->assertSame('ok', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'ok',
        ));

        $this->assertSame([], $cache->writes);
    }

    public function testCachedTransitionsWriteStateLast(): void
    {
        $cache = new class implements Adapter {
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
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $setWrites = array_values(array_filter(
            $cache->writes,
            static fn(array $write): bool => $write[0] === 'set',
        ));

        $this->assertSame(['set', 'users-api:state', CircuitState::OPEN->value], $setWrites[array_key_last($setWrites)]);
    }

    public function testHalfOpenSuccessesCloseTheCircuit(): void
    {
        $breaker = new CircuitBreaker(threshold: 1, timeout: 0, successThreshold: 2);

        $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('probe-1', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-1',
        ));
        $this->assertTrue($breaker->isHalfOpen());
        $this->assertSame(1, $breaker->getSuccessCount());

        $this->assertSame('probe-2', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-2',
        ));

        $this->assertTrue($breaker->isClosed());
        $this->assertSame(0, $breaker->getFailureCount());
        $this->assertSame(0, $breaker->getSuccessCount());
    }

    public function testRecordsTelemetryForCallsFallbacksAndTransitions(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(threshold: 1, timeout: 30, successThreshold: 1, telemetry: $telemetry);

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertSame([1], $telemetry->counters['breaker.calls']->values);
        $this->assertSame([1], $telemetry->counters['breaker.callback_failures']->values);
        $this->assertSame([1], $telemetry->counters['breaker.fallbacks']->values);
        $this->assertSame([1], $telemetry->counters['breaker.transitions']->values);
        $this->assertSame([1, -1], $telemetry->upDownCounters['breaker.active_calls']->values);
        $this->assertSame([1], $telemetry->gauges['breaker.state']->values);
        $this->assertSame([1], $telemetry->gauges['breaker.failures']->values);
        $this->assertSame([0], $telemetry->gauges['breaker.successes']->values);
        $this->assertCount(1, $telemetry->gauges['breaker.event.timestamp']->values);
    }

    public function testPrefixesTelemetryMetricNames(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(threshold: 1, timeout: 30, successThreshold: 1, metricPrefix: '.edge.');
        $breaker->setTelemetry($telemetry);

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertSame([1], $telemetry->counters['edge.breaker.calls']->values);
        $this->assertSame([1], $telemetry->counters['edge.breaker.callback_failures']->values);
        $this->assertSame([1], $telemetry->counters['edge.breaker.fallbacks']->values);
        $this->assertSame([1], $telemetry->counters['edge.breaker.transitions']->values);
        $this->assertSame([1, -1], $telemetry->upDownCounters['edge.breaker.active_calls']->values);
        $this->assertSame([1], $telemetry->gauges['edge.breaker.state']->values);
        $this->assertSame([1], $telemetry->gauges['edge.breaker.failures']->values);
        $this->assertSame([0], $telemetry->gauges['edge.breaker.successes']->values);
        $this->assertCount(1, $telemetry->gauges['edge.breaker.event.timestamp']->values);
        $this->assertArrayNotHasKey('breaker.calls', $telemetry->counters);
    }

    public function testInspectionMethodsDoNotEmitTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(0, $breaker->getFailureCount());
        $this->assertSame(0, $breaker->getSuccessCount());
        $this->assertSame([], $telemetry->gauges['breaker.state']->values);
        $this->assertSame([], $telemetry->gauges['breaker.failures']->values);
        $this->assertSame([], $telemetry->gauges['breaker.successes']->values);
        $this->assertSame([], $telemetry->counters['breaker.calls']->values);
        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
    }

    public function testRareTelemetryInstrumentsAreCreatedOnFirstRecord(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);

        $breaker->trip();

        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertSame([1], $telemetry->counters['breaker.transitions']->values);
        $this->assertCount(1, $telemetry->gauges['breaker.event.timestamp']->values);
    }

    public function testSuccessfulCallsDoNotCreateRareTelemetryInstruments(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $this->assertSame('ok', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'ok',
        ));

        $this->assertSame([1], $telemetry->counters['breaker.calls']->values);
        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
    }

    public function testActiveCallTelemetryUsesPostUpdateState(): void
    {
        $store = new ActiveCallAttributeStore();
        $telemetry = new ActiveCallTelemetry($store);
        $cache = new class implements Adapter {
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
            telemetry: $telemetry,
        );

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe',
        );

        $this->assertSame('probe', $result);
        $this->assertCount(2, $store->attributes);
        $this->assertSame(CircuitState::HALF_OPEN->value, $store->attributes[0]['circuit_breaker.state']);
        $this->assertSame(CircuitState::HALF_OPEN->value, $store->attributes[1]['circuit_breaker.state']);
    }

    public function testRejectsEmptyCacheKeyWhenCacheIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CircuitBreaker(cache: $this->createArrayAdapter(), key: '');
    }

    public function testTripTransitionsToOpen(): void
    {
        $breaker = new CircuitBreaker();

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $breaker->trip();

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertTrue($breaker->isOpen());
    }

    public function testTrippedBreakerShortCircuitsCalls(): void
    {
        $breaker = new CircuitBreaker(threshold: 100, timeout: 30, successThreshold: 1);
        $breaker->trip();

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: function (): never {
                self::fail('Closed callback should not run when the breaker has been tripped.');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertTrue($breaker->isOpen());
    }

    public function testTripIsIdempotent(): void
    {
        $breaker = new CircuitBreaker();

        $breaker->trip();
        $breaker->trip();
        $breaker->trip();

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testTripPersistsStateThroughCacheAdapter(): void
    {
        $cache = $this->createArrayAdapter();
        $first = new CircuitBreaker(cache: $cache, key: 'users-api');
        $first->trip();

        $second = new CircuitBreaker(cache: $cache, key: 'users-api');

        $this->assertTrue($second->isOpen());
    }

    public function testTripEmitsTransitionTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $breaker->trip();

        $this->assertSame([1], $telemetry->counters['breaker.transitions']->values);
        $this->assertSame([1], $telemetry->gauges['breaker.state']->values);
    }

    private function createArrayAdapter(): Adapter
    {
        return new class implements Adapter {
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
    public function __construct(private readonly ActiveCallAttributeStore $store) {}

    /**
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): UpDownCounter {
        if ($name !== 'breaker.active_calls') {
            return parent::createUpDownCounter($name, $unit, $description, $advisory);
        }

        $counter = new class ($this->store) extends UpDownCounter {
            public function __construct(private readonly ActiveCallAttributeStore $store) {}

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
