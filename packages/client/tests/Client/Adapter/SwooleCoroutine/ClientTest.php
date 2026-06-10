<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter\SwooleCoroutine;

use Swoole\Coroutine;
use Utopia\Client\Adapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Client\Adapter\AdapterContract;

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
}
