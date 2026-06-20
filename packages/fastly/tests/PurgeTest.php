<?php

declare(strict_types=1);

namespace Utopia\Fastly\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Fastly\Exception\PurgeException;
use Utopia\Fastly\Fastly;
use Utopia\Psr7\Response\Factory as ResponseFactory;

final class PurgeTest extends TestCase
{
    /**
     * A PSR-18 client that records the request it was given and replies with a
     * canned status, so purge() can be exercised without touching the network.
     */
    private function recordingClient(int $status = 200): ClientInterface
    {
        return new class ($status) implements ClientInterface {
            public ?RequestInterface $request = null;

            public function __construct(private readonly int $status) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new ResponseFactory()->createResponse($this->status);
            }
        };
    }

    public function testPurgeSendsSignedPostToFastly(): void
    {
        $client = $this->recordingClient();
        new Fastly($client, 'svc1', 'secret-token')->purge('homepage');

        $this->assertNotNull($client->request);
        $this->assertSame('POST', $client->request->getMethod());
        $this->assertSame(
            'https://api.fastly.com/service/svc1/purge/homepage',
            (string) $client->request->getUri(),
        );
        $this->assertSame('secret-token', $client->request->getHeaderLine('Fastly-Key'));
        $this->assertSame('application/json', $client->request->getHeaderLine('Accept'));
    }

    public function testServiceIdAndKeyAreUrlEncoded(): void
    {
        $client = $this->recordingClient();
        new Fastly($client, 'svc 1', 'tok')->purge('home/page');

        $this->assertSame(
            'https://api.fastly.com/service/svc%201/purge/home%2Fpage',
            (string) $client->request->getUri(),
        );
    }

    public function testCustomEndpointAndTokenHeader(): void
    {
        $client = $this->recordingClient();
        new Fastly($client, 'svc1', 'tok', 'https://cdn.example.com/service/', 'X-Purge-Key')
            ->purge('a');

        $this->assertSame('https://cdn.example.com/service/svc1/purge/a', (string) $client->request->getUri());
        $this->assertSame('tok', $client->request->getHeaderLine('X-Purge-Key'));
    }

    public function testNon2xxResponseThrows(): void
    {
        $fastly = new Fastly($this->recordingClient(403), 'svc1', 'bad-token');

        $this->expectException(PurgeException::class);
        $this->expectExceptionMessage('Fastly purge failed for key: status 403');
        $fastly->purge('key');
    }
}
