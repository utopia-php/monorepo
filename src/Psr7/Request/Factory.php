<?php

declare(strict_types=1);

namespace Utopia\Psr7\Request;

use JsonException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Part;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;

final readonly class Factory implements RequestFactoryInterface
{
    public function __construct(
        private UriFactoryInterface $uriFactory = new Uri\Factory(),
        private StreamFactoryInterface $streamFactory = new Stream\Factory(),
    ) {}

    public function createRequest(string $method, $uri): RequestInterface
    {
        $uri = $uri instanceof UriInterface ? $uri : $this->uriFactory->createUri((string) $uri);

        return new Request(strtoupper($method), $uri)
            ->withUri($uri);
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     *
     * @throws JsonException
     */
    public function json(string $method, UriInterface|string $uri, mixed $data, array $headers = []): RequestInterface
    {
        $request = $this->body($method, $uri, json_encode($data, JSON_THROW_ON_ERROR), ContentType::JSON, $headers);

        if (!$request->hasHeader(Header::ACCEPT)) {
            return $request->withHeader(Header::ACCEPT, ContentType::JSON);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $headers
     */
    public function form(string $method, UriInterface|string $uri, array $data, array $headers = []): RequestInterface
    {
        return $this->body(
            $method,
            $uri,
            http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            ContentType::FORM_URLENCODED,
            $headers,
        );
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function body(string $method, UriInterface|string $uri, string $body, string $contentType, array $headers = []): RequestInterface
    {
        $request = $this->applyHeaders(
            $this->createRequest($method, $uri),
            $headers,
        )->withBody($this->streamFactory->createStream($body));

        if (!$request->hasHeader(Header::CONTENT_TYPE)) {
            return $request->withHeader(Header::CONTENT_TYPE, $contentType);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, string|array<int, string>> $headers
     */
    public function query(string $method, UriInterface|string $uri, array $parameters, array $headers = []): RequestInterface
    {
        $request = $this->applyHeaders(
            $this->createRequest($method, $uri),
            $headers,
        );

        if ($parameters === []) {
            return $request;
        }

        $uri = $request->getUri();
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        if ($uri->getQuery() !== '') {
            $query = $uri->getQuery() . '&' . $query;
        }

        return $request->withUri($uri->withQuery($query));
    }

    /**
     * @param array<array-key, scalar|Part> $parts
     * @param array<string, string|array<int, string>> $headers
     */
    public function multipart(string $method, UriInterface|string $uri, array $parts, array $headers = []): RequestInterface
    {
        $boundary = $this->boundary();
        $request = $this->applyHeaders(
            $this->createRequest($method, $uri),
            $headers,
        )->withBody($this->streamFactory->createStream($this->multipartBody($boundary, $parts)));

        if (!$request->hasHeader(Header::CONTENT_TYPE)) {
            return $request->withHeader(Header::CONTENT_TYPE, ContentType::MULTIPART_FORM_DATA . '; boundary=' . $boundary);
        }

        return $request;
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    private function applyHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function boundary(): string
    {
        return 'utopia-' . bin2hex(random_bytes(16));
    }

    /**
     * @param array<array-key, scalar|Part> $parts
     */
    private function multipartBody(string $boundary, array $parts): string
    {
        $body = '';

        foreach ($parts as $name => $part) {
            $part = $part instanceof Part ? $part : Part::field((string) $name, (string) $part);
            $body .= '--' . $boundary . "\r\n";
            $body .= $this->multipartHeaders($part);
            $body .= "\r\n";
            $body .= $part->bodyContent() . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    private function multipartHeaders(Part $part): string
    {
        $headers = [
            Header::CONTENT_DISPOSITION => 'form-data; name="' . $this->escapeQuotedString($part->name()) . '"',
        ];

        if ($part->filename() !== null) {
            $headers[Header::CONTENT_DISPOSITION] .= '; filename="' . $this->escapeQuotedString($part->filename()) . '"';
        }

        if ($part->contentType() !== null) {
            $headers[Header::CONTENT_TYPE] = $part->contentType();
        }

        foreach ($part->headers() as $name => $value) {
            $headers[$name] = $value;
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function escapeQuotedString(string $value): string
    {
        return addcslashes($value, '\\"');
    }
}
