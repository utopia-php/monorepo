<?php

declare(strict_types=1);

namespace Utopia\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;

use function Swoole\Coroutine\run;

use Utopia\WebSocket\Client;

final class AdapterTest extends TestCase
{
    private function getWebsocket(string $host, int $port): Client
    {
        return new Client('ws://' . $host . ':' . $port, [
            'timeout' => 10,
        ]);
    }

    public function testSwoole(): void
    {
        $this->testServer('127.0.0.1', 18081);
    }

    public function testWorkerman(): void
    {
        $this->testServer('127.0.0.1', 18082);
    }

    public function testSwooleTimesOutStalledSends(): void
    {
        $this->testPendingSends(false);
    }

    public function testSwooleReleasesSendsAfterPeerClose(): void
    {
        $this->testPendingSends(true);
    }

    public function testSwooleRecoversFromBriefStall(): void
    {
        run(function (): void {
            $client = $this->getWebsocket('127.0.0.1', 18081);
            $http = new HttpClient('127.0.0.1', 18081);
            $http->set(['timeout' => 2]);

            try {
                $client->connect();
                $baseline = $this->getInfo($http);
                $client->send('flood');
                $this->waitForPendingSends($http, $baseline['coroutines']);

                // Resume reading before the send timeout. Every frame must arrive.
                for ($i = 0; $i < 300; $i++) {
                    $frame = $client->receive();
                    $this->assertSame(65536, \strlen($frame));
                    $this->assertSame(\sprintf('%06d:', $i), substr($frame, 0, 7));
                }
                $client->send('ping');
                $this->assertSame('pong', $client->receive());
            } finally {
                $client->close();
                $http->close();
            }
        });
    }

    private function testPendingSends(bool $closePeer): void
    {
        run(function () use ($closePeer): void {
            $slow = $this->getWebsocket('127.0.0.1', 18081);
            $healthy = $this->getWebsocket('127.0.0.1', 18081);
            $http = new HttpClient('127.0.0.1', 18081);
            $http->set(['timeout' => 2]);

            try {
                $slow->connect();
                $healthy->connect();
                $baseline = $this->getInfo($http);
                $slow->send('flood');
                $this->waitForPendingSends($http, $baseline['coroutines']);

                if ($closePeer) {
                    // Close the TCP socket with unread frames, as in the upstream repro.
                    $slow->close();
                }

                $deadline = microtime(true) + 6;
                do {
                    Coroutine::sleep(0.05);
                    $info = $this->getInfo($http);
                } while (($info['connections'] !== 1 || $info['native_connections'] !== 2
                    || $info['coroutines'] !== $baseline['coroutines']) && microtime(true) < $deadline);

                $this->assertSame(1, $info['connections'], 'The stalled client must be disconnected');
                // The native server also counts this HTTP request. Checking it catches
                // graceful closes that run onClose but retain the undrained socket.
                $this->assertSame(2, $info['native_connections'], 'The stalled socket must be released');
                $this->assertSame($baseline['coroutines'], $info['coroutines'], 'Pending sends must finish');
                $healthy->send('ping');
                $this->assertSame('pong', $healthy->receive());
            } finally {
                $slow->close();
                $healthy->close();
                $http->close();
            }
        });
    }

    private function waitForPendingSends(HttpClient $http, int $baseline): void
    {
        $deadline = microtime(true) + 2;
        do {
            Coroutine::sleep(0.01);
            $info = $this->getInfo($http);
        } while ((!$info['flood_complete'] || $info['coroutines'] <= $baseline + 10) && microtime(true) < $deadline);

        $this->assertTrue($info['flood_complete']);
        $this->assertGreaterThan($baseline + 10, $info['coroutines'], 'The test must exercise suspended sends');
    }

    /**
     * @return array{connections: int, native_connections: int, coroutines: int, flood_complete: bool}
     */
    private function getInfo(HttpClient $http): array
    {
        $this->assertTrue($http->get('/info'));

        return json_decode($http->body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function testServer(string $host, int $port): void
    {
        run(function () use ($host, $port): void {
            $client = $this->getWebsocket($host, $port);
            $client->connect();

            $client->send('ping');
            $this->assertSame('pong', $client->receive());
            $this->assertEquals(true, $client->isConnected());

            $clientA = $this->getWebsocket($host, $port);
            $clientA->connect();
            $clientB = $this->getWebsocket($host, $port);
            $clientB->connect();

            $clientA->send('ping');
            $this->assertSame('pong', $clientA->receive());
            $clientB->send('pong');
            $this->assertSame('ping', $clientB->receive());

            $clientA->send('broadcast');
            $this->assertSame('broadcast', $client->receive());
            $this->assertSame('broadcast', $clientA->receive());
            $this->assertSame('broadcast', $clientB->receive());

            $clientB->send('broadcast');
            $this->assertSame('broadcast', $client->receive());
            $this->assertSame('broadcast', $clientA->receive());
            $this->assertSame('broadcast', $clientB->receive());

            $clientA->close();
            $clientB->close();

            $client->send('disconnect');
            $this->assertSame('disconnect', $client->receive());

            $client->close();
            $this->assertFalse($client->isConnected());
        });
    }
}
