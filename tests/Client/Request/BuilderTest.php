<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Request;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Utopia\Client\Request\Builder as RequestBuilder;
use Utopia\Client\Request\Multipart\Part as RequestPart;
use Utopia\Client\Response\Decoder as ResponseDecoder;
use Utopia\Psr7\RequestFactory;
use Utopia\Psr7\ResponseFactory;
use Utopia\Psr7\StreamFactory;

final class BuilderTest extends TestCase
{
    public function testItCreatesJsonRequests(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->json('POST', 'https://example.com/users', [
            'name' => 'Ada',
        ]);

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.com/users', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"name":"Ada"}', (string) $request->getBody());
    }

    public function testItAllowsJsonHeaderOverrides(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->json('POST', 'https://example.com/users', ['name' => 'Ada'], [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/merge-patch+json',
        ]);

        $this->assertSame('application/vnd.api+json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/merge-patch+json', $request->getHeaderLine('Content-Type'));
    }

    public function testItCreatesFormRequests(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->form('POST', 'https://example.com/users', [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('email=ada%40example.com&name=Ada%20Lovelace', (string) $request->getBody());
    }

    public function testItCreatesRawBodyRequests(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->body('PUT', 'https://example.com/archive', 'raw-bytes', 'application/octet-stream');

        $this->assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));
        $this->assertSame('raw-bytes', (string) $request->getBody());
    }

    public function testItCreatesQueryRequests(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->query('GET', 'https://example.com/users?active=1', [
            'page' => 2,
            'search' => 'Ada Lovelace',
        ]);

        $this->assertSame('https://example.com/users?active=1&page=2&search=Ada%20Lovelace', (string) $request->getUri());
    }

    public function testItCreatesMultipartRequests(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->multipart('POST', 'https://example.com/upload', [
            'name' => 'Ada',
            'avatar' => RequestPart::body('avatar', 'image-bytes', 'ada.png', 'image/png'),
        ]);

        $contentType = $request->getHeaderLine('Content-Type');
        $body = (string) $request->getBody();

        $this->assertStringStartsWith('multipart/form-data; boundary=utopia-', $contentType);
        $this->assertStringContainsString('Content-Disposition: form-data; name="name"', $body);
        $this->assertStringContainsString("\r\n\r\nAda\r\n", $body);
        $this->assertStringContainsString('Content-Disposition: form-data; name="avatar"; filename="ada.png"', $body);
        $this->assertStringContainsString('Content-Type: image/png', $body);
        $this->assertStringContainsString("\r\n\r\nimage-bytes\r\n", $body);
    }

    public function testItTerminatesMultipartRequestsWithClosingBoundary(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->multipart('POST', 'https://example.com/upload', [
            'name' => 'Ada',
        ]);

        $boundary = substr($request->getHeaderLine('Content-Type'), \strlen('multipart/form-data; boundary='));

        $this->assertStringEndsWith('--' . $boundary . "--\r\n", (string) $request->getBody());
    }

    public function testItCreatesMultipartRequestsWithRepeatedFieldNames(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->multipart('POST', 'https://example.com/upload', [
            RequestPart::field('tag', 'math'),
            RequestPart::field('tag', 'history'),
        ]);

        $body = (string) $request->getBody();

        $this->assertSame(2, substr_count($body, 'Content-Disposition: form-data; name="tag"'));
        $this->assertStringContainsString("\r\n\r\nmath\r\n", $body);
        $this->assertStringContainsString("\r\n\r\nhistory\r\n", $body);
    }

    public function testItCreatesMultipartRequestsWithCustomPartHeaders(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->multipart('POST', 'https://example.com/upload', [
            'payload' => RequestPart::body('payload', 'contents', headers: [
                'X-Part-ID' => '123',
            ]),
        ]);

        $this->assertStringContainsString('X-Part-ID: 123', (string) $request->getBody());
    }

    public function testItEscapesMultipartDispositionQuotedStrings(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $request = $builder->multipart('POST', 'https://example.com/upload', [
            RequestPart::body('profile"name', 'contents', 'ada"notes\\.txt'),
        ]);

        $this->assertStringContainsString(
            'Content-Disposition: form-data; name="profile\"name"; filename="ada\"notes\\\\.txt"',
            (string) $request->getBody(),
        );
    }

    public function testItThrowsWhenJsonCannotBeEncoded(): void
    {
        $builder = new RequestBuilder(new RequestFactory(), new StreamFactory());

        $this->expectException(JsonException::class);

        $builder->json('POST', 'https://example.com/users', "\xB1\x31");
    }

    public function testItDecodesJsonResponses(): void
    {
        $response = new ResponseFactory()
            ->createResponse()
            ->withBody(new StreamFactory()->createStream('{"name":"Ada"}'));

        $decoded = new ResponseDecoder()->json($response);

        $this->assertSame(['name' => 'Ada'], $decoded);
    }

    public function testItDecodesFormResponses(): void
    {
        $response = new ResponseFactory()
            ->createResponse()
            ->withBody(new StreamFactory()->createStream('email=ada%40example.com&name=Ada%20Lovelace'));

        $decoded = new ResponseDecoder()->form($response);

        $this->assertSame([
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ], $decoded);
    }

    public function testItDecodesMultipartResponses(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n"
            . "\r\n"
            . "Ada\r\n"
            . "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"ada.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . "image-bytes\r\n"
            . "--abc123--\r\n";

        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')
            ->withBody(new StreamFactory()->createStream($body));

        $parts = new ResponseDecoder()->multipart($response);

        $this->assertCount(2, $parts);
        $this->assertSame('name', $parts[0]->name());
        $this->assertNull($parts[0]->filename());
        $this->assertSame('Ada', $parts[0]->body());
        $this->assertSame('avatar', $parts[1]->name());
        $this->assertSame('ada.png', $parts[1]->filename());
        $this->assertSame('image/png', $parts[1]->contentType());
        $this->assertSame('image-bytes', $parts[1]->body());
    }

    public function testItDecodesMultipartResponsesWithQuotedBoundariesAndBodyCrLf(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"log\"\r\n"
            . "\r\n"
            . "line 1\r\nline 2\r\n"
            . "--abc123--\r\n";

        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/mixed; boundary="abc123"')
            ->withBody(new StreamFactory()->createStream($body));

        $parts = new ResponseDecoder()->multipart($response);

        $this->assertCount(1, $parts);
        $this->assertSame('log', $parts[0]->name());
        $this->assertSame("line 1\r\nline 2", $parts[0]->body());
    }

    public function testItDecodesMultipartResponsesWithPreambleAndEpilogue(): void
    {
        $body = "ignored preamble\r\n"
            . "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n"
            . "\r\n"
            . "Ada\r\n"
            . "--abc123--\r\n"
            . 'ignored epilogue';

        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')
            ->withBody(new StreamFactory()->createStream($body));

        $parts = new ResponseDecoder()->multipart($response);

        $this->assertCount(1, $parts);
        $this->assertSame('name', $parts[0]->name());
        $this->assertSame('Ada', $parts[0]->body());
    }

    public function testItDoesNotSplitMultipartBodiesOnBoundaryTextInsideContent(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"log\"\r\n"
            . "\r\n"
            . "line one has --abc123 inside it\r\n"
            . "line two\r\n"
            . "--abc123--\r\n";

        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')
            ->withBody(new StreamFactory()->createStream($body));

        $parts = new ResponseDecoder()->multipart($response);

        $this->assertCount(1, $parts);
        $this->assertSame("line one has --abc123 inside it\r\nline two", $parts[0]->body());
    }

    public function testItDecodesMultipartResponsesWithRepeatedFieldNames(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n"
            . "\r\n"
            . "math\r\n"
            . "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n"
            . "\r\n"
            . "history\r\n"
            . "--abc123--\r\n";

        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')
            ->withBody(new StreamFactory()->createStream($body));

        $parts = new ResponseDecoder()->multipart($response);

        $this->assertCount(2, $parts);
        $this->assertSame('tag', $parts[0]->name());
        $this->assertSame('math', $parts[0]->body());
        $this->assertSame('tag', $parts[1]->name());
        $this->assertSame('history', $parts[1]->body());
    }

    public function testItDecodesMultipartResponsesWithUnquotedDispositionParametersAndDuplicateHeaders(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=file; filename=ada.txt\r\n"
            . "X-Trace: one\r\n"
            . "X-Trace: two\r\n"
            . "\r\n"
            . "contents\r\n"
            . "--abc123--\r\n";

        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')
            ->withBody(new StreamFactory()->createStream($body));

        $parts = new ResponseDecoder()->multipart($response);

        $this->assertCount(1, $parts);
        $this->assertSame('file', $parts[0]->name());
        $this->assertSame('ada.txt', $parts[0]->filename());
        $this->assertSame(['one', 'two'], $parts[0]->header('x-trace'));
        $this->assertSame('one, two', $parts[0]->headerLine('X-Trace'));
    }

    public function testItRejectsMultipartResponsesWithoutBoundaries(): void
    {
        $response = new ResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/mixed')
            ->withBody(new StreamFactory()->createStream(''));

        $this->expectException(InvalidArgumentException::class);

        new ResponseDecoder()->multipart($response);
    }
}
