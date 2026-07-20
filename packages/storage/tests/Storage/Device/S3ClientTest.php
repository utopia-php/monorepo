<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Tls;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\RetryStrategy;
use Utopia\Storage\Exception\NotFoundException;

/**
 * Client stub that replays scripted responses and records every request.
 * Implements the full utopia-php/client Adapter so the Retry decorator can wrap it.
 */
final class ScriptedClient implements Adapter
{
    /**
     * @var array<RequestInterface>
     */
    public array $requests = [];

    /**
     * @param  array<ResponseInterface>  $responses
     */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if (! $response instanceof ResponseInterface) {
            throw new \RuntimeException('No scripted response left for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return $response;
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $response = $this->sendRequest($request);
        $sink((string) $response->getBody());

        return $response;
    }

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
}

final class S3ClientTest extends TestCase
{
    private function device(ScriptedClient $client): S3
    {
        return new S3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://s3.example.com',
            region: 'us-east-1',
            client: new Retry($client, new RetryStrategy(delay: 0.0)),
        );
    }

    private function slowDown(): Response
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>';

        return new Response(503, body: new Stream($body))->withHeader('content-type', 'application/xml');
    }

    public function testWriteSendsSignedRequest(): void
    {
        $client = new ScriptedClient([new Response(200)]);

        $this->assertTrue($this->device($client)->write('/root/file.txt', 'Hello World', 'text/plain'));
        $this->assertCount(1, $client->requests);

        $request = $client->requests[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('s3.example.com', $request->getUri()->getHost());
        $this->assertSame('/root/file.txt', $request->getUri()->getPath());
        $this->assertSame('Hello World', (string) $request->getBody());
        $this->assertSame('text/plain', $request->getHeaderLine('content-type'));
        $this->assertSame('private', $request->getHeaderLine('x-amz-acl'));
        $this->assertSame(hash('sha256', 'Hello World'), $request->getHeaderLine('x-amz-content-sha256'));
        $this->assertSame(base64_encode(md5('Hello World', true)), $request->getHeaderLine('content-md5'));
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=test-key/', $request->getHeaderLine('authorization'));
        $this->assertSame('utopia-php/storage', $request->getHeaderLine('user-agent'));
    }

    public function testTransientErrorIsRetriedUntilSuccess(): void
    {
        $client = new ScriptedClient([$this->slowDown(), $this->slowDown(), new Response(200)]);

        $this->assertTrue($this->device($client)->write('/root/file.txt', 'Hello World', 'text/plain'));
        $this->assertCount(3, $client->requests);
    }

    public function testTransientErrorRetriesAreExhausted(): void
    {
        $client = new ScriptedClient([$this->slowDown(), $this->slowDown(), $this->slowDown(), $this->slowDown()]);

        try {
            $this->device($client)->write('/root/file.txt', 'Hello World', 'text/plain');
            self::fail('Expected exception after exhausting retries');
        } catch (\Exception $e) {
            $this->assertSame(503, $e->getCode());
        }

        // Initial attempt plus the default three retries.
        $this->assertCount(4, $client->requests);
    }

    public function testNoSuchKeyBecomesNotFoundException(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>';
        $client = new ScriptedClient([new Response(404, body: new Stream($body))]);

        $this->expectException(NotFoundException::class);
        $this->device($client)->read('/root/missing.txt');
    }

    public function testXmlResponseIsDecoded(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><KeyCount>2</KeyCount><IsTruncated>false</IsTruncated><MaxKeys>1000</MaxKeys></ListBucketResult>';
        $client = new ScriptedClient([new Response(200, body: new Stream($body))->withHeader('content-type', 'application/xml')]);

        $files = $this->device($client)->getFiles('/root/testing');

        $this->assertSame(2, $files['KeyCount']);
        $this->assertFalse($files['IsTruncated']);
        $this->assertSame(1000, $files['MaxKeys']);
    }
}
