<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client;
use Utopia\Client\Adapter;
use Utopia\Psr7\RequestFactory;
use Utopia\Psr7\ResponseFactory;
use ValueError;

final class ClientTest extends TestCase
{
    public function testItDecoratesConfigurableAdapters(): void
    {
        $request = new RequestFactory()->createRequest('GET', 'https://example.com');
        $adapter = new RecordingAdapter();
        $client = new Client($adapter);
        $configured = $client
            ->withTimeout(5.5)
            ->withConnectTimeout(1.25);

        $response = $configured->sendRequest($request);

        $this->assertSame('', $client->sendRequest($request)->getHeaderLine('X-Timeout'));
        $this->assertSame('5.5', $response->getHeaderLine('X-Timeout'));
        $this->assertSame('1.25', $response->getHeaderLine('X-Connect-Timeout'));
    }

    public function testItRejectsInvalidTimeouts(): void
    {
        $client = new Client(new RecordingAdapter());

        $this->expectException(ValueError::class);

        $client->withTimeout(-1);
    }
}

final class RecordingAdapter implements Adapter
{
    public function __construct(
        private ?float $timeout = null,
        private ?float $connectTimeout = null,
    ) {}

    public function withTimeout(float $seconds): static
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        $clone = clone $this;
        $clone->connectTimeout = $seconds;

        return $clone;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        unset($request);

        $response = new ResponseFactory()->createResponse();

        if ($this->timeout !== null) {
            $response = $response->withHeader('X-Timeout', (string) $this->timeout);
        }

        if ($this->connectTimeout !== null) {
            return $response->withHeader('X-Connect-Timeout', (string) $this->connectTimeout);
        }

        return $response;
    }
}
