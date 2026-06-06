<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter\Curl;

use Utopia\Client\Adapter;
use Utopia\Client\Adapter\Curl\Client;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Client\Adapter\AdapterContract;

final class ClientTest extends AdapterContract
{
    /**
     * @param array<int, mixed> $transportOptions
     */
    protected function createAdapter(array $transportOptions = []): Adapter
    {
        return new Client(new Response\Factory(), new Stream\Factory(), $transportOptions);
    }

    protected function runAdapter(callable $callback): void
    {
        $callback();
    }

    protected function requireAdapterAvailable(): void
    {
        $this->assertTrue(\extension_loaded('curl'), 'The curl extension is required.');
    }

    /**
     * @return array<int, mixed>
     */
    protected function invalidTransportOptions(): array
    {
        return [
            999_999_999 => true,
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function timeoutOptions(float $timeout, ?float $connectTimeout = null): array
    {
        $options = [
            \CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
        ];

        if ($connectTimeout !== null) {
            $options[\CURLOPT_CONNECTTIMEOUT_MS] = (int) round($connectTimeout * 1000);
        }

        return $options;
    }

    /**
     * @return array<int, mixed>
     */
    protected function proxyOptions(int $port): array
    {
        return [
            \CURLOPT_PROXY => '127.0.0.1:' . $port,
            \CURLOPT_PROXYTYPE => \CURLPROXY_SOCKS5,
        ];
    }
}
