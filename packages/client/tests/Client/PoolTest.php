<?php

declare(strict_types=1);

namespace Utopia\Tests\Client;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Options;
use Utopia\Client\Pool;
use Utopia\Client\Tls;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;

final class PoolTest extends TestCase
{
    public function testItBorrowsAConnectionToSendARequest(): void
    {
        $pool = new Pool($this->connections(fn(): \Utopia\Tests\Client\FakeClient => new FakeClient(200)));

        $this->assertSame(200, $pool->sendRequest($this->request())->getStatusCode());
    }

    public function testItBorrowsAConnectionToStreamARequest(): void
    {
        $pool = new Pool($this->connections(fn(): \Utopia\Tests\Client\FakeClient => new FakeClient(200)));
        $received = '';

        $response = $pool->stream($this->request(), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame('chunk', $received);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testItReclaimsTheConnectionSoItCanBeReused(): void
    {
        $created = 0;
        $pool = new Pool($this->connections(function () use (&$created): FakeClient {
            $created++;

            return new FakeClient(200);
        }, size: 1));

        $pool->sendRequest($this->request());
        $pool->sendRequest($this->request());

        $this->assertSame(1, $created);
    }

    public function testItForwardsPerRequestOptionsToTheBorrowedAdapter(): void
    {
        $adapter = new OptionsRecordingAdapter();
        $pool = new Pool($this->connections(fn(): OptionsRecordingAdapter => $adapter, size: 1));
        $options = new Options(timeout: 2.5);

        $pool->sendRequest($this->request(), $options);
        $pool->stream($this->request(), static function (string $chunk): void {}, $options);
        $pool->sendRequest($this->request());

        $this->assertSame([$options, $options, null], $adapter->options);
    }

    public function testItRejectsPerRequestOptionsForPlainClients(): void
    {
        $pool = new Pool($this->connections(fn(): \Utopia\Tests\Client\FakeClient => new FakeClient(200)));

        $this->expectException(InvalidArgumentException::class);

        $pool->sendRequest($this->request(), new Options(timeout: 1));
    }

    /**
     * Mirrors how a caller builds a pool: a factory returning a concrete client
     * type flows through to `new Pool()` without needing the intersection type.
     *
     * @template T of \Psr\Http\Client\ClientInterface&StreamingClientInterface
     *
     * @param callable(): T $init
     *
     * @return Connections<T>
     */
    private function connections(callable $init, int $size = 4): Connections
    {
        return new Connections(new Stack(), 'test', $size, $init);
    }

    private function request(): RequestInterface
    {
        return new Request\Factory()->createRequest(Method::GET, 'https://example.com');
    }
}

final readonly class FakeClient implements \Psr\Http\Client\ClientInterface, StreamingClientInterface
{
    public function __construct(private int $status) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response($this->status);
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $sink('chunk');

        return new Response($this->status);
    }
}

final class OptionsRecordingAdapter implements Adapter
{
    /**
     * @var array<int, Options|null>
     */
    public array $options = [];

    public function withTimeout(float $seconds): static
    {
        return $this;
    }

    public function withConnectTimeout(float $seconds): static
    {
        return $this;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return $this;
    }

    public function withCustomCA(string $path): static
    {
        return $this;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return $this;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this;
    }

    public function sendRequest(RequestInterface $request, ?Options $options = null): ResponseInterface
    {
        $this->options[] = $options;

        return new Response(200);
    }

    public function stream(RequestInterface $request, callable $sink, ?Options $options = null): ResponseInterface
    {
        $this->options[] = $options;
        $sink('chunk');

        return new Response(200);
    }
}
