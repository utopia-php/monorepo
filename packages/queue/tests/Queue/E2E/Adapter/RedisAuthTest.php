<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Connection\RedisCluster;

/**
 * Runs against the `redis-auth` and `redis-cluster-auth` compose services. Both
 * set `requirepass secretpw` for the default user and define an ACL user
 * `worker` with password `workerpw`.
 */
final class RedisAuthTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 16380;
    private const int CLUSTER_PORT = 17100;
    private const string PASSWORD = 'secretpw';
    private const string USER = 'worker';
    private const string USER_PASSWORD = 'workerpw';

    public function testPasswordAuthenticatesDefaultUser(): void
    {
        $this->assertRoundTrip(new Redis(self::HOST, self::PORT, null, self::PASSWORD));
    }

    public function testUserAndPasswordAuthenticateAclUser(): void
    {
        $this->assertRoundTrip(new Redis(self::HOST, self::PORT, self::USER, self::USER_PASSWORD));
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $this->assertRejected(
            new Redis(self::HOST, self::PORT),
            new Redis(self::HOST, self::PORT, null, self::PASSWORD),
        );
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->assertRejected(
            new Redis(self::HOST, self::PORT, self::USER, 'not-the-password'),
            new Redis(self::HOST, self::PORT, null, self::PASSWORD),
        );
    }

    public function testClusterPasswordAuthenticatesDefaultUser(): void
    {
        $this->assertRoundTrip(new RedisCluster([$this->clusterSeed()], user: null, password: self::PASSWORD));
    }

    public function testClusterUserAndPasswordAuthenticateAclUser(): void
    {
        $this->assertRoundTrip(new RedisCluster([$this->clusterSeed()], user: self::USER, password: self::USER_PASSWORD));
    }

    public function testClusterMissingCredentialsAreRejected(): void
    {
        $this->assertRejected(
            new RedisCluster([$this->clusterSeed()]),
            new RedisCluster([$this->clusterSeed()], password: self::PASSWORD),
        );
    }

    public function testClusterWrongPasswordIsRejected(): void
    {
        $this->assertRejected(
            new RedisCluster([$this->clusterSeed()], user: self::USER, password: 'not-the-password'),
            new RedisCluster([$this->clusterSeed()], password: self::PASSWORD),
        );
    }

    private function clusterSeed(): string
    {
        return self::HOST . ':' . self::CLUSTER_PORT;
    }

    /**
     * A credentialed connection can write a job and read it back.
     */
    private function assertRoundTrip(Connection $connection): void
    {
        $key = 'auth-test-' . uniqid();

        $this->assertTrue($connection->rightPush($key, 'payload'));
        $this->assertSame('payload', $connection->rightPop($key, 1));
    }

    /**
     * The observable contract of rejected credentials: the push throws, and the
     * server holds nothing for that key when a trusted connection looks.
     */
    private function assertRejected(Connection $rejected, Connection $trusted): void
    {
        $key = 'auth-test-' . uniqid();
        $thrown = null;

        try {
            $rejected->rightPush($key, 'payload');
        } catch (\RedisException|\RedisClusterException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Push with rejected credentials must throw');
        $this->assertSame(0, $trusted->listSize($key), 'Rejected push must not reach the server');
    }
}
