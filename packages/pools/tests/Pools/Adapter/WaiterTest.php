<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Pools\Adapter\Swoole;
use Utopia\Pools\Pool;

final class WaiterTest extends TestCase
{
    public function testDiscardingAFailedBorrowerWakesAnExistingWaiter(): void
    {
        $failure = new \RuntimeException('request failed');
        $results = [];
        $created = 0;
        $pool = new Pool(new Swoole(), 'discard', 1, static function () use (&$created): object {
            ++$created;
            return new \stdClass();
        }, 0.5);
        Coroutine\run(static function () use ($pool, $failure, &$results): void {
            $completed = new Channel(2);
            Coroutine::create(static function () use ($pool, $failure, $completed): void {
                try {
                    $pool->use(static function () use ($failure): never {
                        Coroutine::sleep(0.02);
                        throw $failure;
                    });
                } catch (\Throwable $error) {
                    $completed->push($error);
                }
            });
            Coroutine::create(static function () use ($pool, $completed): void {
                try {
                    $completed->push($pool->use(static fn(): string => 'replacement'));
                } catch (\Throwable $error) {
                    $completed->push($error);
                }
            });
            $results = [$completed->pop(1), $completed->pop(1)];
        });
        $this->assertContains($failure, $results);
        $this->assertContains('replacement', $results);
        $this->assertSame(2, $created);
        $this->assertTrue($pool->isFull());
    }

    public function testFailedCreationWakesAnExistingWaiterWithoutRetryingTheCreator(): void
    {
        $failure = new \RuntimeException('connection failed');
        $created = 0;
        $results = [];
        $pool = new Pool(new Swoole(), 'creation', 1, static function () use (&$created, $failure): string {
            if (++$created === 1) {
                Coroutine::sleep(0.02);
                throw $failure;
            }
            return 'replacement';
        }, 0.5);
        Coroutine\run(static function () use ($pool, &$results): void {
            $completed = new Channel(2);
            for ($index = 0; $index < 2; ++$index) {
                Coroutine::create(static function () use ($pool, $completed): void {
                    try {
                        $completed->push($pool->use(static fn(string $value): string => $value));
                    } catch (\Throwable $error) {
                        $completed->push($error);
                    }
                });
            }
            $results = [$completed->pop(1), $completed->pop(1)];
        });
        $this->assertContains($failure, $results);
        $this->assertContains('replacement', $results);
        $this->assertSame(2, $created);
        $this->assertTrue($pool->isFull());
    }
    public function testCapacityNotificationsDoNotExtendTheOriginalDeadline(): void
    {
        $adapter = new Swoole();
        $pool = new Pool($adapter, 'deadline', 1, static fn(): string => 'occupied', 0.04);
        $failure = null;
        $elapsed = 0.0;
        Coroutine\run(static function () use ($pool, $adapter, &$failure, &$elapsed): void {
            $connection = $pool->pop();
            $started = microtime(true);
            Coroutine::create(static function () use ($adapter): void {
                for ($index = 0; $index < 10; ++$index) {
                    Coroutine::sleep(0.01);
                    $adapter->notify();
                }
            });
            try {
                $pool->pop();
            } catch (\Exception $error) {
                $failure = $error;
            }
            $elapsed = microtime(true) - $started;
            $pool->reclaim($connection);
        });
        $this->assertInstanceOf(\Exception::class, $failure);
        $this->assertLessThan(0.1, $elapsed);
        $this->assertTrue($pool->isFull());
    }

    public function testSeveralWaitersCompeteForFreedCapacityWithoutOversubscribing(): void
    {
        $active = 0;
        $maximum = 0;
        $results = [];
        $pool = new Pool(new Swoole(), 'capacity', 2, static fn(): object => new \stdClass(), 0.5);
        Coroutine\run(static function () use ($pool, &$active, &$maximum, &$results): void {
            $completed = new Channel(8);
            for ($index = 0; $index < 8; ++$index) {
                Coroutine::create(static function () use ($pool, $completed, &$active, &$maximum): void {
                    try {
                        $pool->use(static function () use (&$active, &$maximum): never {
                            ++$active;
                            $maximum = max($maximum, $active);
                            try {
                                Coroutine::sleep(0.002);
                                throw new \RuntimeException('discard');
                            } finally {
                                --$active;
                            }
                        });
                    } catch (\Throwable $error) {
                        $completed->push($error);
                    }
                });
            }
            for ($index = 0; $index < 8; ++$index) {
                $results[] = $completed->pop(1);
            }
        });
        foreach ($results as $error) {
            $this->assertInstanceOf(\RuntimeException::class, $error);
            $this->assertSame('discard', $error->getMessage());
        }
        $this->assertSame(2, $maximum);
        $this->assertSame(0, $active);
        $this->assertTrue($pool->isFull());
    }

}
