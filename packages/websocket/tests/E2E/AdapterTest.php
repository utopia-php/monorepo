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

    public function testSwooleDisconnectsSlowConsumer(): void
    {
        run(function (): void {
            $slow = $this->getWebsocket('127.0.0.1', 18081);
            $healthy = $this->getWebsocket('127.0.0.1', 18081);
            $http = new HttpClient('127.0.0.1', 18081);
            $http->set(['timeout' => 2]);

            try {
                $slow->connect();
                $healthy->connect();
                $healthy->send('ping');
                $this->assertSame('pong', $healthy->receive());

                // Do not receive from this client: its socket must stop draining.
                $slow->send('flood');
                $deadline = microtime(true) + 5;
                do {
                    Coroutine::sleep(0.05);
                    $this->assertTrue($http->get('/info'));
                    $info = json_decode($http->body, true, flags: JSON_THROW_ON_ERROR);
                } while ($info['connections'] !== 1 && microtime(true) < $deadline);

                $this->assertSame(1, $info['connections'], 'The stalled client must be disconnected');
                $healthy->send('ping');
                $this->assertSame('pong', $healthy->receive());
            } finally {
                $slow->close();
                $healthy->close();
                $http->close();
            }
        });
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
