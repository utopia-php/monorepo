<?php

declare(strict_types=1);

namespace Utopia\Tests\Client;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator;
use Utopia\Client\Options;
use Utopia\Client\Tls;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;

final class DecoratorTest extends TestCase
{
    public function testItDelegatesSendRequestToTheInnerAdapter(): void
    {
        $decorator = new PassthroughDecorator(new SwappableAdapter(200));

        $this->assertSame(200, $decorator->sendRequest($this->request())->getStatusCode());
    }

    public function testItDelegatesStreamRequestToTheInnerAdapter(): void
    {
        $decorator = new PassthroughDecorator(new SwappableAdapter(200));
        $received = '';

        $response = $decorator->stream($this->request(), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame('chunk', $received);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testItForwardsConfigurationToAReconfiguredInnerClone(): void
    {
        $decorator = new PassthroughDecorator(new SwappableAdapter(200));
        $configured = $decorator->withTimeout(5);

        $this->assertNotSame($decorator, $configured);
        $this->assertInstanceOf(PassthroughDecorator::class, $configured);
        $this->assertSame(200, $decorator->sendRequest($this->request())->getStatusCode());
        $this->assertSame(299, $configured->sendRequest($this->request())->getStatusCode());
    }

    public function testItForwardsPerRequestOptionsToTheInnerAdapter(): void
    {
        $decorator = new PassthroughDecorator(new SwappableAdapter(200));
        $options = new Options(timeout: 1);

        $sent = $decorator->sendRequest($this->request(), $options);
        $streamed = $decorator->stream($this->request(), static function (string $chunk): void {}, $options);

        $this->assertSame(288, $sent->getStatusCode());
        $this->assertSame(288, $streamed->getStatusCode());
    }

    private function request(): RequestInterface
    {
        return new Request\Factory()->createRequest(Method::GET, 'https://example.com');
    }
}

final class PassthroughDecorator extends Decorator {}

final class SwappableAdapter implements Adapter
{
    public function __construct(private int $status = 200) {}

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->status = 299;

        return $clone;
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
        return new Response($options instanceof \Utopia\Client\Options ? 288 : $this->status);
    }

    public function stream(RequestInterface $request, callable $sink, ?Options $options = null): ResponseInterface
    {
        $sink('chunk');

        return new Response($options instanceof \Utopia\Client\Options ? 288 : $this->status);
    }
}
