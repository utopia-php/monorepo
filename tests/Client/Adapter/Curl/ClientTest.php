<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter\Curl;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use ReflectionMethod;
use Utopia\Client\Adapter\Curl\Client;
use Utopia\Psr7\RequestFactory;
use Utopia\Psr7\ResponseFactory;
use Utopia\Psr7\StreamFactory;

final class ClientTest extends TestCase
{
    public function testItRequiresAbsoluteUris(): void
    {
        $requestFactory = new RequestFactory();
        $client = new Client(new ResponseFactory(), new StreamFactory());

        $this->expectException(ClientExceptionInterface::class);

        $client->sendRequest($requestFactory->createRequest('GET', '/relative'));
    }

    public function testItSendsRequestWithCurl(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is not installed.');
        }

        $port = $this->availablePort();
        $server = $this->startServer($port);

        try {
            $requestFactory = new RequestFactory();
            $streamFactory = new StreamFactory();
            $client = new Client(new ResponseFactory(), $streamFactory);
            $request = $requestFactory->createRequest('POST', 'http://127.0.0.1:' . $port . '/echo')
                ->withHeader('Content-Type', 'text/plain')
                ->withHeader('X-Custom', 'sent')
                ->withBody($streamFactory->createStream('hello'));
            $response = $client->sendRequest($request);

            $this->assertSame(202, $response->getStatusCode());
            $this->assertSame('text/plain;charset=UTF-8', $response->getHeaderLine('Content-Type'));
            $this->assertSame('POST:/echo:sent:hello', (string) $response->getBody());
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testItReturnsClientAndServerErrorResponsesWithoutThrowing(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is not installed.');
        }

        $port = $this->availablePort();
        $server = $this->startServer($port);

        try {
            $requestFactory = new RequestFactory();
            $client = new Client(new ResponseFactory(), new StreamFactory());

            $notFound = $client->sendRequest($requestFactory->createRequest('GET', 'http://127.0.0.1:' . $port . '/not-found'));
            $serverError = $client->sendRequest($requestFactory->createRequest('GET', 'http://127.0.0.1:' . $port . '/server-error'));

            $this->assertSame(404, $notFound->getStatusCode());
            $this->assertSame('missing', (string) $notFound->getBody());
            $this->assertSame(500, $serverError->getStatusCode());
            $this->assertSame('failed', (string) $serverError->getBody());
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testItDoesNotFollowRedirectsByDefault(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is not installed.');
        }

        $port = $this->availablePort();
        $server = $this->startServer($port);

        try {
            $requestFactory = new RequestFactory();
            $client = new Client(new ResponseFactory(), new StreamFactory());

            $response = $client->sendRequest($requestFactory->createRequest('GET', 'http://127.0.0.1:' . $port . '/redirect'));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/final', $response->getHeaderLine('Location'));
            $this->assertSame('redirect', (string) $response->getBody());
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testItPreservesDuplicateMixedCaseHeadersAndBinaryBodies(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is not installed.');
        }

        $port = $this->availablePort();
        $server = $this->startServer($port);

        try {
            $requestFactory = new RequestFactory();
            $client = new Client(new ResponseFactory(), new StreamFactory());

            $headers = $client->sendRequest($requestFactory->createRequest('GET', 'http://127.0.0.1:' . $port . '/headers'));
            $binary = $client->sendRequest($requestFactory->createRequest('GET', 'http://127.0.0.1:' . $port . '/binary'));

            $this->assertSame(204, $headers->getStatusCode());
            $this->assertSame(['one', 'two'], $headers->getHeader('x-trace'));
            $this->assertSame('Value', $headers->getHeaderLine('X-Mixed-Case'));
            $this->assertSame('application/octet-stream', $binary->getHeaderLine('Content-Type'));
            $this->assertSame("\x00\x01hello\xff", (string) $binary->getBody());
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testItThrowsNetworkExceptionsForTransportFailures(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is not installed.');
        }

        $requestFactory = new RequestFactory();
        $port = $this->availablePort();
        $client = new Client(new ResponseFactory(), new StreamFactory(), [
            \CURLOPT_CONNECTTIMEOUT_MS => 100,
            \CURLOPT_TIMEOUT_MS => 100,
        ]);

        $this->expectException(NetworkExceptionInterface::class);

        $client->sendRequest($requestFactory->createRequest('GET', 'http://127.0.0.1:' . $port));
    }

    public function testItParsesTheFinalCurlHeaderBlock(): void
    {
        $client = new Client(new ResponseFactory(), new StreamFactory());
        $method = new ReflectionMethod($client, 'parseHeaderBlock');

        $parsed = $method->invoke($client, "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200\r\nX-Trace: one\r\nX-Trace: two\r\n\r\n");

        $this->assertSame([
            'protocol' => '1.1',
            'status' => 200,
            'reason' => '',
            'headers' => [
                'X-Trace' => ['one', 'two'],
            ],
        ], $parsed);
    }

    /**
     * @return resource
     */
    private function startServer(int $port): mixed
    {
        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, \dirname(__DIR__, 3) . '/server.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($server)) {
            self::fail('Unable to start PHP test server.');
        }

        unset($pipes);
        $this->waitForServer($port);

        return $server;
    }

    private function waitForServer(int $port): void
    {
        $deadline = microtime(true) + 5;

        do {
            $connection = @fsockopen('127.0.0.1', $port);

            if (\is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('PHP test server did not start.');
    }

    private function availablePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if (!\is_resource($server)) {
            self::fail('Unable to find an available TCP port: ' . $errorCode . ' ' . $errorMessage);
        }

        $name = stream_socket_get_name($server, false);

        fclose($server);

        if ($name === false) {
            self::fail('Unable to read TCP port.');
        }

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);

        if (!\is_int($port)) {
            self::fail('Unable to parse TCP port.');
        }

        return $port;
    }
}
