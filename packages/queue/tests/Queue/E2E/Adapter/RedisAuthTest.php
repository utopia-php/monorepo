<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Connection\Redis;

/**
 * Runs against the `redis-auth` compose service: `requirepass secretpw` for the
 * default user and an ACL user `worker` with password `workerpw`.
 */
final class RedisAuthTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 16380;

    public function testPasswordAuthenticatesDefaultUser(): void
    {
        $connection = new Redis(self::HOST, self::PORT, null, 'secretpw');
        $key = 'auth-test-default-' . uniqid();

        $this->assertTrue($connection->rightPush($key, 'payload'));
        $this->assertSame('payload', $connection->rightPop($key, 1));
    }

    public function testUserAndPasswordAuthenticateAclUser(): void
    {
        $connection = new Redis(self::HOST, self::PORT, 'worker', 'workerpw');
        $key = 'auth-test-acl-' . uniqid();

        $this->assertTrue($connection->rightPush($key, 'payload'));
        $this->assertSame('payload', $connection->rightPop($key, 1));
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $connection = new Redis(self::HOST, self::PORT);

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('NOAUTH');

        $connection->rightPush('auth-test-missing', 'payload');
    }

    public function testWrongPasswordFailsLoudly(): void
    {
        $connection = new Redis(self::HOST, self::PORT, 'worker', 'not-the-password');

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('WRONGPASS');

        $connection->rightPush('auth-test-wrong', 'payload');
    }
}
