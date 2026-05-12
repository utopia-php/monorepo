<?php

namespace Utopia\Tests\e2e;

use Utopia\CircuitBreaker\Adapter\Redis as RedisAdapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use PHPUnit\Framework\TestCase;

final class RedisAdapterTest extends TestCase
{
    private ?object $redis = null;
    private string $prefix;

    protected function setUp(): void
    {
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            self::markTestSkipped('The redis extension is required for Redis E2E tests.');
        }

        $host = getenv('BREAKER_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BREAKER_REDIS_PORT') ?: 6379);
        $this->prefix = 'breaker-e2e:' . bin2hex(random_bytes(4)) . ':';
        $redisClass = 'Redis';
        $this->redis = new $redisClass();

        try {
            if (!$this->redis->connect($host, $port, 2.0)) {
                $error = method_exists($this->redis, 'getLastError') ? $this->redis->getLastError() : null;
                $message = is_string($error) && $error !== '' ? ': ' . $error : '.';
                self::markTestSkipped(sprintf('Redis E2E server is not reachable at %s:%d%s', $host, $port, $message));
            }
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis E2E server is not reachable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis === null || !$this->redis->isConnected()) {
            return;
        }

        foreach ($this->redis->keys($this->prefix . '*') ?: [] as $key) {
            $this->redis->del($key);
        }

        $this->redis->close();
        $this->redis = null;
    }

    public function testRedisAdapterStoresValuesInRedis(): void
    {
        $adapter = new RedisAdapter($this->redis, $this->prefix);
        $adapter->set('state', 'open');
        $adapter->set('count', 2);

        self::assertSame('open', $adapter->get('state'));
        self::assertSame('2', $adapter->get('count'));
        self::assertSame(3, $adapter->increment('count'));
        self::assertSame('3', $adapter->get('count'));
        self::assertSame('3', $this->redis->get($this->prefix . 'count'));

        $adapter->delete('count');

        self::assertNull($adapter->get('count'));
    }

    public function testCircuitBreakerSharesStateThroughRedis(): void
    {
        $cache = new RedisAdapter($this->redis, $this->prefix);
        $first = new CircuitBreaker(threshold: 1, timeout: 0, successThreshold: 2, cache: $cache, key: 'users-api');
        $second = new CircuitBreaker(threshold: 1, timeout: 0, successThreshold: 2, cache: $cache, key: 'users-api');

        $first->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertTrue($second->isHalfOpen());
        self::assertSame(0, $second->getFailureCount());

        self::assertSame('probe-1', $second->call(
            open: static fn () => 'fallback',
            close: static fn () => 'closed',
            halfOpen: static fn () => 'probe-1'
        ));
        self::assertSame(1, $first->getSuccessCount());

        self::assertSame('probe-2', $first->call(
            open: static fn () => 'fallback',
            close: static fn () => 'closed',
            halfOpen: static fn () => 'probe-2'
        ));

        self::assertTrue($second->isClosed());
        self::assertSame('closed', $this->redis->get($this->prefix . 'users-api:state'));
    }
}
