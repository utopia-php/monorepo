<?php

namespace Utopia\Tests;

use RuntimeException;

/**
 * Where the package's compose services listen on the host. Ports are offset so
 * they never collide with a local stack — keep them in sync with
 * docker-compose.yml.
 */
final class Services
{
    public const string HOST = '127.0.0.1';

    public const int REDIS_PORT = 16381;

    public const array SHARD_PORTS = [16382, 16383, 16384];

    /** The cluster advertises its own ports, so they are not offset. */
    public const array CLUSTER_SEEDS = ['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002'];

    public const int MEMCACHED_PORT = 11212;

    public const int HAZELCAST_PORT = 15701;

    /**
     * Runs a docker compose command against this package's stack.
     */
    public static function compose(string ...$arguments): void
    {
        self::docker('compose', '-f', \dirname(__DIR__, 2) . '/docker-compose.yml', ...$arguments);
    }

    /**
     * Runs a docker command against one of the compose containers, so the
     * reconnect tests can take a service down and bring it back up.
     */
    public static function docker(string ...$arguments): void
    {
        $command = 'docker ' . implode(' ', $arguments);
        exec($command . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new RuntimeException("`{$command}` failed: " . implode("\n", $output));
        }
    }
}
