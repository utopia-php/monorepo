<?php

declare(strict_types=1);

namespace Utopia\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client;
use Utopia\Client\Adapter;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use ValueError;

final class ClientTest extends TestCase
{
    public function testItDecoratesConfigurableAdapters(): void
    {
        $request = new Request\Factory()->createRequest('GET', 'https://example.com');
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

    public function testItAppliesDefaultHeadersImmutablyWithoutOverridingRequestHeaders(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter());
        $configured = $client->withHeaders([
            'Accept' => 'application/json',
            'X-Trace' => ['one', 'two'],
        ]);

        $plain = $client->sendRequest(
            $requestFactory->createRequest('GET', 'https://example.com'),
        );
        $response = $configured->sendRequest(
            $requestFactory->createRequest('GET', 'https://example.com')
                ->withHeader('Accept', 'application/xml'),
        );

        $this->assertSame('', $plain->getHeaderLine('X-Request-Accept'));
        $this->assertSame('application/xml', $response->getHeaderLine('X-Request-Accept'));
        $this->assertSame('one, two', $response->getHeaderLine('X-Request-Trace'));
    }

    public function testItAppliesAuthDefaultsWithoutOverridingRequestAuthorization(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter());

        $basic = $client
            ->withBasicAuth('ada', 'secret')
            ->sendRequest($requestFactory->createRequest('GET', 'https://example.com'));
        $bearer = $client
            ->withBearerAuth('token')
            ->sendRequest($requestFactory->createRequest('GET', 'https://example.com'));
        $override = $client
            ->withBearerAuth('token')
            ->sendRequest(
                $requestFactory->createRequest('GET', 'https://example.com')
                    ->withHeader('Authorization', 'Digest custom'),
            );

        $this->assertSame('Basic YWRhOnNlY3JldA==', $basic->getHeaderLine('X-Request-Authorization'));
        $this->assertSame('Bearer token', $bearer->getHeaderLine('X-Request-Authorization'));
        $this->assertSame('Digest custom', $override->getHeaderLine('X-Request-Authorization'));
    }

    public function testItAppliesBaseUriToRelativeRequests(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter())
            ->withBaseUri('https://api.example.com/v1');

        $relative = $client->sendRequest(
            $requestFactory->createRequest('GET', 'users?active=1'),
        );
        $absolutePath = $client->sendRequest(
            $requestFactory->createRequest('GET', '/status'),
        );
        $absoluteUri = $client->sendRequest(
            $requestFactory->createRequest('GET', 'https://other.example.com/users'),
        );

        $this->assertSame('https://api.example.com/v1/users?active=1', $relative->getHeaderLine('X-Request-Uri'));
        $this->assertSame('api.example.com', $relative->getHeaderLine('X-Request-Host'));
        $this->assertSame('https://api.example.com/status', $absolutePath->getHeaderLine('X-Request-Uri'));
        $this->assertSame('https://other.example.com/users', $absoluteUri->getHeaderLine('X-Request-Uri'));
    }

    public function testItRejectsRelativeBaseUris(): void
    {
        $client = new Client(new RecordingAdapter());

        $this->expectException(InvalidArgumentException::class);

        $client->withBaseUri('/api');
    }

    public function testItStreamsThroughTheAdapterApplyingBaseUriAndHeaders(): void
    {
        $requestFactory = new Request\Factory();
        $received = '';
        $client = new Client(new RecordingAdapter())
            ->withBaseUri('https://api.example.com/v1')
            ->withHeaders(['Accept' => 'application/json']);

        $response = $client->streamRequest(
            $requestFactory->createRequest('GET', 'users'),
            function (string $chunk) use (&$received): void {
                $received .= $chunk;
            },
        );

        $this->assertSame('chunk', $received);
        $this->assertSame('https://api.example.com/v1/users', $response->getHeaderLine('X-Request-Uri'));
        $this->assertSame('application/json', $response->getHeaderLine('X-Request-Accept'));
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
        $response = new Response\Factory()
            ->createResponse()
            ->withHeader('X-Request-Uri', (string) $request->getUri())
            ->withHeader('X-Request-Host', $request->getHeaderLine('Host'))
            ->withHeader('X-Request-Accept', $request->getHeaderLine('Accept'))
            ->withHeader('X-Request-Authorization', $request->getHeaderLine('Authorization'))
            ->withHeader('X-Request-Trace', $request->getHeaderLine('X-Trace'));

        if ($this->timeout !== null) {
            $response = $response->withHeader('X-Timeout', (string) $this->timeout);
        }

        if ($this->connectTimeout !== null) {
            return $response->withHeader('X-Connect-Timeout', (string) $this->connectTimeout);
        }

        return $response;
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function streamRequest(RequestInterface $request, callable $sink): ResponseInterface
    {
        $sink('chunk');

        return $this->sendRequest($request);
    }
}
