<?php

declare(strict_types=1);

namespace Utopia\Http;

use PHPUnit\Framework\TestCase;
use Utopia\Http\Adapter\Swoole\Server;

final class SwooleTelemetryTest extends TestCase
{
    public function testTelemetryNameMapsStatsKeys(): void
    {
        // the _count / _num substring becomes a ".count" segment under swoole.*;
        // other underscores are left as-is
        $this->assertSame('swoole.request.count', Server::telemetryName('request_count'));
        $this->assertSame('swoole.connection.count', Server::telemetryName('connection_num'));
        $this->assertSame('swoole.worker_request.count', Server::telemetryName('worker_request_count'));
        // keys without those suffixes are namespaced verbatim
        $this->assertSame('swoole.start_time', Server::telemetryName('start_time'));
        // only the trailing suffix is rewritten, never an infix occurrence
        $this->assertSame('swoole.request_num_bytes', Server::telemetryName('request_num_bytes'));
    }

    public function testPerWorkerStatsKeys(): void
    {
        // Per-worker keys must stay disjoint from global ones, and keep the
        // upstream "coroutine_peek_num" typo so they match Server::stats().
        $this->assertContains('worker_request_count', Server::PER_WORKER_STATS_KEYS);
        $this->assertContains('coroutine_peek_num', Server::PER_WORKER_STATS_KEYS);
        $this->assertNotContains('coroutine_peak_num', Server::PER_WORKER_STATS_KEYS);
        $this->assertSame(Server::PER_WORKER_STATS_KEYS, array_values(array_unique(Server::PER_WORKER_STATS_KEYS)));
    }
}
