<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Swoole\Coroutine;
use Throwable;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Pool;
use Utopia\Pools\Adapter\Swoole as SwoolePool;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr7\Request\Factory;
use Utopia\Tests\Server\Tls;

final class TlsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'curl' => ['curl'];
        yield 'swoole' => ['swoole'];
    }

    #[DataProvider('transports')]
    public function testTrustedMatchingPeerAndConnectionReuse(string $transport): void
    {
        $requests = Tls::serve(function (int $port, string $ca) use ($transport): void {
            $client = $this->client($transport)->withCustomCA($ca)->withConnectionReuse();
            $this->runTransport($transport, function () use ($client, $port): void {
                for ($i = 0; $i < 2; $i++) {
                    $response = $client->sendRequest($this->request($port));
                    $this->assertSame(200, $response->getStatusCode());
                    $this->assertSame('ok', (string) $response->getBody());
                }
            });
        });
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]['connection'], $requests[1]['connection']);
    }

    #[DataProvider('transports')]
    public function testUntrustedPeerIsRejectedByDefaultBeforeSending(string $transport): void
    {
        $requests = Tls::serve(function (int $port) use ($transport): void {
            $this->runTransport($transport, function () use ($transport, $port): void {
                $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $this->client($transport)->sendRequest($this->request($port)));
            });
        });
        $this->assertSame([], $requests);
    }

    #[DataProvider('transports')]
    public function testTrustedWrongHostnameIsRejectedBeforeSendingCredentialsOrBody(string $transport): void
    {
        $requests = Tls::serve(function (int $port, string $ca) use ($transport): void {
            $client = $this->client($transport)->withCustomCA($ca)->withSslVerification();
            $this->runTransport($transport, function () use ($client, $port): void {
                $request = new Factory()->text('POST', 'https://localhost:' . $port . '/', 'private-body')
                    ->withHeader('Authorization', 'Bearer test-token')
                    ->withHeader('Host', 'other.invalid');
                $failure = $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $client->sendRequest($request));
                $this->assertSame($request->getUri(), $failure->getRequest()->getUri());
            });
        }, peerName: 'other.invalid');
        $this->assertSame([], $requests);
    }

    public function testNativeHostnameOptionCannotOverrideRequestIdentity(): void
    {
        $requests = Tls::serve(function (int $port, string $ca): void {
            $client = new Client(new SwooleAdapter(settings: ['ssl_host_name' => 'other.invalid']))
                ->withCustomCA($ca)->withSslVerification();
            $this->runTransport('swoole', function () use ($client, $port): void {
                $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $client->sendRequest($this->request($port)));
            });
        }, peerName: 'other.invalid');
        $this->assertSame([], $requests);
    }

    #[DataProvider('transports')]
    public function testStreamingAlsoRejectsWrongHostnameBeforeSending(string $transport): void
    {
        $delivered = '';
        $requests = Tls::serve(function (int $port, string $ca) use ($transport, &$delivered): void {
            $client = $this->client($transport)->withCustomCA($ca)->withSslVerification();
            $this->runTransport($transport, function () use ($client, $port, &$delivered): void {
                $this->assertTlsFailure(function () use ($client, $port, &$delivered): \Psr\Http\Message\ResponseInterface {
                    return $client->stream($this->request($port), function (string $chunk) use (&$delivered): void {
                        $delivered .= $chunk;
                    });
                });
            });
        }, peerName: 'other.invalid');
        $this->assertSame('', $delivered);
        $this->assertSame([], $requests);
    }

    #[DataProvider('transports')]
    public function testRedirectCannotReuseThePreviousHostnameForVerification(string $transport): void
    {
        $requests = Tls::serve(function (int $port, string $ca) use ($transport): void {
            $client = $this->client($transport)->withCustomCA($ca)->withSslVerification()->withFollowRedirects()->withConnectionReuse();
            $this->runTransport($transport, function () use ($client, $port): void {
                $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $client->sendRequest($this->request($port, '/redirect')));
            });
        });
        $this->assertCount(1, $requests);
        $this->assertSame('/redirect', $requests[0]['path']);
    }

    #[DataProvider('transports')]
    public function testReusedClientRejectsAnotherHostAndRecovers(string $transport): void
    {
        $requests = Tls::serve(function (int $port, string $ca) use ($transport): void {
            $client = $this->client($transport)->withCustomCA($ca)->withSslVerification()->withConnectionReuse();
            $this->runTransport($transport, function () use ($client, $port): void {
                $this->assertSame(200, $client->sendRequest($this->request($port))->getStatusCode());
                $wrongHost = new Factory()->createRequest('POST', 'https://127.0.0.1:' . $port . '/private');
                $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $client->sendRequest($wrongHost));
                $this->assertSame(200, $client->sendRequest($this->request($port))->getStatusCode());
            });
        });
        $this->assertCount(2, $requests);
        $this->assertSame(['/', '/'], array_column($requests, 'path'));
    }

    public function testPooledClientReturnsResourcesAfterHostnameFailure(): void
    {
        $requests = Tls::serve(function (int $port, string $ca): void {
            $this->runTransport('swoole', function () use ($port, $ca): void {
                $pool = new Pool(new Connections(
                    new SwoolePool(),
                    'tls-test',
                    1,
                    fn(): Client => $this->client('swoole')->withCustomCA($ca)->withSslVerification()->withConnectionReuse(),
                    timeout: 1.0,
                ));
                $this->assertSame(200, $pool->sendRequest($this->request($port))->getStatusCode());
                $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $pool->sendRequest(new Factory()->createRequest('GET', 'https://127.0.0.1:' . $port . '/private')));
                $this->assertSame(200, $pool->sendRequest($this->request($port))->getStatusCode());
            });
        });
        $this->assertCount(2, $requests);
        $this->assertSame(['/', '/'], array_column($requests, 'path'));
    }

    #[DataProvider('transports')]
    public function testExplicitInsecureOptOutRemainsAvailable(string $transport): void
    {
        $requests = Tls::serve(function (int $port) use ($transport): void {
            $client = $this->client($transport)->withSslVerification(false);
            $this->runTransport($transport, function () use ($client, $port): void {
                $this->assertSame(200, $client->sendRequest($this->request($port))->getStatusCode());
            });
        }, peerName: 'other.invalid');
        $this->assertCount(1, $requests);
    }

    #[DataProvider('transports')]
    public function testIpLiteralCannotMatchANumericDnsSan(string $transport): void
    {
        $requests = Tls::serve(function (int $port, string $ca) use ($transport): void {
            $client = $this->client($transport)->withCustomCA($ca)->withSslVerification();
            $this->runTransport($transport, function () use ($client, $port): void {
                $request = new Factory()->createRequest('GET', 'https://127.0.0.1:' . $port . '/');
                $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $client->sendRequest($request));
            });
        }, peerName: '127.0.0.1');
        $this->assertSame([], $requests);
    }

    #[DataProvider('transports')]
    public function testVerifiedIpSanRequiresCurl(string $transport): void
    {
        $requests = Tls::serve(function (int $port, string $ca) use ($transport): void {
            $client = $this->client($transport)->withCustomCA($ca)->withSslVerification();
            $this->runTransport($transport, function () use ($client, $port, $transport): void {
                $request = new Factory()->createRequest('GET', 'https://127.0.0.1:' . $port . '/');
                if ($transport === 'swoole') {
                    $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $client->sendRequest($request));
                } else {
                    $this->assertSame(200, $client->sendRequest($request)->getStatusCode());
                }
            });
        }, ipAddress: '127.0.0.1');
        $this->assertCount($transport === 'swoole' ? 0 : 1, $requests);
    }

    public function testVerifiedIpv6LiteralAlsoFailsClosed(): void
    {
        $this->runTransport('swoole', function (): void {
            $request = new Factory()->createRequest('GET', 'https://[::1]:1/');
            $this->assertTlsFailure(fn(): \Psr\Http\Message\ResponseInterface => $this->client('swoole')->sendRequest($request));
        });
    }

    public function testIpLiteralAllowsExplicitInsecureOptOut(): void
    {
        $requests = Tls::serve(function (int $port): void {
            $this->runTransport('swoole', function () use ($port): void {
                $request = new Factory()->createRequest('GET', 'https://127.0.0.1:' . $port . '/');
                $this->assertSame(200, $this->client('swoole')->withSslVerification(false)->sendRequest($request)->getStatusCode());
            });
        });
        $this->assertCount(1, $requests);
    }

    private function client(string $transport): Client
    {
        return new Client($transport === 'swoole' ? new SwooleAdapter() : new CurlAdapter())
            ->withConnectTimeout(3)->withTimeout(5);
    }

    private function request(int $port, string $path = '/'): RequestInterface
    {
        return new Factory()->createRequest('GET', 'https://localhost:' . $port . $path);
    }

    /** @param callable(): mixed $send */
    private function assertTlsFailure(callable $send): TlsException
    {
        $failure = null;
        try {
            $send();
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $this->assertInstanceOf(TlsException::class, $failure);
        return $failure;
    }

    /** @param callable(): void $test */
    private function runTransport(string $transport, callable $test): void
    {
        if ($transport !== 'swoole') {
            $test();
            return;
        }

        $failure = null;
        Coroutine\run(static function () use ($test, &$failure): void {
            try {
                $test();
            } catch (Throwable $throwable) {
                $failure = $throwable;
            }
        });
        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }
}
