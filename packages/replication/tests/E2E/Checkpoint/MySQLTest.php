<?php

namespace Utopia\Replication\Tests\E2E\Checkpoint;

use PDO;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Replication\Checkpoint\MySQL;

/**
 * Integration test for the MySQL checkpoint store against a live MySQL 8.
 *
 * Opt-in: skipped unless REPLICATION_TEST_HOST is set.
 *
 *   REPLICATION_TEST_HOST=127.0.0.1 REPLICATION_TEST_PORT=3306 \
 *   REPLICATION_TEST_USER=root REPLICATION_TEST_PASS=password \
 *   vendor/bin/phpunit tests/E2E/Checkpoint/MySQLTest.php
 */
class MySQLTest extends TestCase
{
    private const string DATABASE = 'replication_checkpoint_test';

    private string $host;
    private int $port;
    private string $user;
    private string $pass;

    protected function setUp(): void
    {
        $host = getenv('REPLICATION_TEST_HOST');
        if ($host === false) {
            $this->markTestSkipped('Set REPLICATION_TEST_HOST to run checkpoint integration tests.');
        }

        $this->host = $host;
        $this->port = (int) (getenv('REPLICATION_TEST_PORT') ?: '3306');
        $this->user = getenv('REPLICATION_TEST_USER') ?: 'root';
        $this->pass = getenv('REPLICATION_TEST_PASS') ?: 'password';

        $pdo = new PDO(
            "mysql:host={$this->host};port={$this->port}",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $pdo->exec('CREATE DATABASE `' . self::DATABASE . '`');
    }

    protected function tearDown(): void
    {
        if (!isset($this->host)) {
            return;
        }
        $pdo = new PDO(
            "mysql:host={$this->host};port={$this->port}",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
    }

    public function testPersistsAndResumes(): void
    {
        $before = 'unset';
        $after = 'unset';
        Coroutine\run(function () use (&$before, &$after) {
            $checkpoint = new MySQL($this->host, $this->port, $this->user, $this->pass, key: 'fra', database: self::DATABASE, interval: 0.0);

            $before = $checkpoint->get();
            $checkpoint->set('uuid:1-5');
            $checkpoint->set('uuid:1-9'); // upsert
            $checkpoint->flush();

            // A fresh instance reads the persisted value back.
            $after = (new MySQL($this->host, $this->port, $this->user, $this->pass, key: 'fra', database: self::DATABASE))->get();
        });

        $this->assertNull($before);
        $this->assertSame('uuid:1-9', $after);
    }

    public function testKeysAreIsolated(): void
    {
        $fra = 'unset';
        $syd = 'unset';
        Coroutine\run(function () use (&$fra, &$syd) {
            (new MySQL($this->host, $this->port, $this->user, $this->pass, key: 'fra', database: self::DATABASE, interval: 0.0))->set('fra-pos');
            (new MySQL($this->host, $this->port, $this->user, $this->pass, key: 'syd', database: self::DATABASE, interval: 0.0))->set('syd-pos');

            $fra = (new MySQL($this->host, $this->port, $this->user, $this->pass, key: 'fra', database: self::DATABASE))->get();
            $syd = (new MySQL($this->host, $this->port, $this->user, $this->pass, key: 'syd', database: self::DATABASE))->get();
        });

        $this->assertSame('fra-pos', $fra);
        $this->assertSame('syd-pos', $syd);
    }
}
