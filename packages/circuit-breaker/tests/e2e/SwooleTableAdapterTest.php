<?php

namespace Utopia\Tests\e2e;

use Utopia\CircuitBreaker\Adapter\AdapterException;
use Utopia\CircuitBreaker\Adapter\SwooleTable;
use Utopia\CircuitBreaker\CircuitBreaker;
use PHPUnit\Framework\TestCase;

final class SwooleTableAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class)) {
            self::markTestSkipped('The swoole extension is not installed.');
        }
    }

    public function testSwooleTableAdapterSharesValuesAcrossInstances(): void
    {
        $table = SwooleTable::createTable(32);
        $first = new SwooleTable($table, 'test:');
        $second = new SwooleTable($table, 'test:');

        $first->set('state', 'half_open');
        $first->set('failures', 2);

        self::assertSame('half_open', $second->get('state'));
        self::assertSame(2, $second->get('failures'));
        self::assertSame(3, $second->increment('failures'));
        self::assertSame(3, $first->get('failures'));
        self::assertSame(1, $first->increment('missing-counter'));
        self::assertSame(1, $second->get('missing-counter'));
        $first->set('empty-string', '');
        self::assertSame('', $second->get('empty-string'));

        $first->delete('failures');

        self::assertNull($second->get('failures'));
    }

    public function testSwooleTableAdapterRejectsIncrementingStrings(): void
    {
        $adapter = new SwooleTable(SwooleTable::createTable(32), 'test:');
        $adapter->set('state', 'open');

        $this->expectException(AdapterException::class);

        $adapter->increment('state');
    }

    public function testSwooleTableAdapterHashesLongKeysToFitTableLimits(): void
    {
        $adapter = new SwooleTable(SwooleTable::createTable(32), 'test:');
        $key = str_repeat('service-', 20) . 'state';

        $adapter->set($key, 'open');

        self::assertSame('open', $adapter->get($key));
    }

    public function testCircuitBreakerSharesStateThroughSwooleTable(): void
    {
        $cache = new SwooleTable(SwooleTable::createTable(32), 'breaker-test:');
        $first = new CircuitBreaker(threshold: 1, timeout: 0, successThreshold: 2, cache: $cache, key: 'billing-api');
        $second = new CircuitBreaker(threshold: 1, timeout: 0, successThreshold: 2, cache: $cache, key: 'billing-api');

        $first->call(
            open: static fn () => 'fallback',
            close: static function (): void {
                throw new \RuntimeException('failed');
            }
        );

        self::assertTrue($second->isHalfOpen());

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
        self::assertSame(0, $second->getFailureCount());
        self::assertSame(0, $second->getSuccessCount());
    }
}
