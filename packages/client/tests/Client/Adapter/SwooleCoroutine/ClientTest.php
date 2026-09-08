<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter\SwooleCoroutine;

use Swoole\Coroutine;
use Throwable;
use Utopia\Client\Adapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Client\Adapter\AdapterContract;
use Utopia\Tests\Server\Http;

final class ClientTest extends AdapterContract
{
    /**
     * @param array<string, mixed> $transportOptions
     */
    protected function createAdapter(array $transportOptions = []): Adapter
    {
        return new Client(new Response\Factory(), new Stream\Factory(), $transportOptions);
    }

    protected function runAdapter(callable $callback): void
    {
        Coroutine\run($callback);
    }

    public function testItReconnectsBeforePostingToAnAbruptlyClosedIdleTlsConnection(): void
    {
        $connections = Http::dropsFirstKeepAliveConnection(function (int $port): void {
            $client = $this->createAdapter()->withConnectionReuse()->withSslVerification(false);
            Coroutine\run(function () use ($client, $port): void {
                for ($i = 0; $i < 4; $i++) {
                    $body = 'event-' . $i;
                    $request = new Request\Factory()->createRequest(Method::POST, 'https://127.0.0.1:' . $port . '/')
                        ->withHeader('Content-Length', (string) \strlen($body))
                        ->withBody(new Stream\Factory()->createStream($body));
                    $response = $client->sendRequest($request);
                    $this->assertSame(200, $response->getStatusCode());
                    $this->assertSame($body, (string) $response->getBody());
                    Coroutine::sleep(0.05);
                }
            });
        }, tls: true);

        $this->assertSame(2, $connections);
    }

    public function testItDoesNotReplayAPostWhenThePeerDropsItsResponse(): void
    {
        $thrown = null;
        $connections = Http::dropsFirstKeepAliveConnection(function (int $port) use (&$thrown): void {
            $client = $this->createAdapter()->withConnectionReuse()->withSslVerification(false);
            Coroutine\run(function () use ($client, $port, &$thrown): void {
                $factory = new Request\Factory();
                $uri = 'https://127.0.0.1:' . $port . '/';
                $this->assertSame(200, $client->sendRequest($factory->createRequest(Method::GET, $uri))->getStatusCode());
                $request = $factory->createRequest(Method::POST, $uri)
                    ->withHeader('Content-Length', '5')
                    ->withBody(new Stream\Factory()->createStream('event'));
                try {
                    $client->sendRequest($request);
                } catch (Throwable $throwable) {
                    $thrown = $throwable;
                }
            });
        }, tls: true, dropResponse: true);

        $this->assertInstanceOf(NetworkException::class, $thrown);
        $this->assertSame(1, $connections);
    }

    public function testItRequiresCoroutineContext(): void
    {
        $client = $this->createAdapter();
        $request = new Request\Factory()->createRequest(Method::GET, 'https://example.com');

        $this->expectException(AdapterPreconditionException::class);

        $client->sendRequest($request);
    }

    protected function requireAdapterAvailable(): void
    {
        $this->assertTrue(\extension_loaded('swoole'), 'The swoole extension is required.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function invalidTransportOptions(): array
    {
        return [
            'timeout' => [],
        ];
    }

    /**
     * @return array<string, float>
     */
    protected function timeoutOptions(float $timeout, ?float $connectTimeout = null): array
    {
        $options = [
            'timeout' => $timeout,
        ];

        if ($connectTimeout !== null) {
            $options['connect_timeout'] = $connectTimeout;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function proxyOptions(int $port): array
    {
        return [
            'socks5_host' => '127.0.0.1',
            'socks5_port' => $port,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function followRedirectsTransportOptions(bool $enabled): array
    {
        return [
            'follow_location' => $enabled,
        ];
    }
}
