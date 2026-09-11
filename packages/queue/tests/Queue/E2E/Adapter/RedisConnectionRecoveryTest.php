<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Connection\Redis;

/**
 * A Redis connection must survive its socket being closed underneath it.
 *
 * phpredis retries a dropped connection on its own, but once the peer stays
 * away past that budget it parks the client in a failed state for good. A
 * worker that lived through a broker failover longer than those retries then
 * fails every command with "went away" until the process restarts. Runs
 * against the compose redis on 16379.
 */
final class RedisConnectionRecoveryTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 16379;

    private function connection(): NoRetryRedis
    {
        return new NoRetryRedis(self::HOST, self::PORT);
    }

    private function killClient(int $clientId): void
    {
        $killer = new \Redis();
        $killer->connect(self::HOST, self::PORT, 1);
        $this->assertSame(1, $killer->rawCommand('CLIENT', 'KILL', 'ID', (string) $clientId));
        $killer->close();
    }

    public function testCommandsRecoverAfterThePeerClosesTheSocket(): void
    {
        $connection = $this->connection();
        $key = 'tests.recovery.' . uniqid();

        $this->assertTrue($connection->set($key, 'before'));
        $this->assertNotNull($connection->clientId);

        $this->killClient($connection->clientId);

        $this->assertTrue($connection->remove($key), 'the first command after the socket died must run on a fresh connection');
        $this->assertTrue($connection->set($key, 'after'));
        $this->assertSame('after', $connection->get($key), 'later commands must keep working on the fresh connection');

        $connection->remove($key);
    }

    public function testFailureStillSurfacesWhenTheServerIsUnreachable(): void
    {
        $connection = new Redis(self::HOST, 1);

        $this->expectException(\RedisException::class);

        $connection->remove('tests.recovery.unreachable');
    }
}

/**
 * A connection whose phpredis client has no reconnect budget, so a closed
 * socket lands it in the failed state on the first command rather than after
 * an outage; and which remembers its server-side client id so a test can kill
 * exactly that socket.
 */
final class NoRetryRedis extends Redis
{
    public ?int $clientId = null;

    #[\Override]
    protected function getRedis(): \Redis
    {
        $redis = parent::getRedis();

        if ($this->clientId === null) {
            $redis->setOption(\Redis::OPT_MAX_RETRIES, 0);
            $this->clientId = (int) $redis->rawCommand('CLIENT', 'ID');
        }

        return $redis;
    }
}
