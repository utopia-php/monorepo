<?php

declare(strict_types=1);

namespace Utopia;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Client\Adapter;
use Utopia\Psr7\Header;
use Utopia\Psr7\Uri;

final class Client implements ClientInterface
{
    /**
     * @var array<string, array{name: string, values: array<int, string>}>
     */
    private array $headers = [];

    private ?UriInterface $baseUri = null;

    public function __construct(
        private Adapter $adapter,
    ) {}

    public function withTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withTimeout($seconds);

        return $clone;
    }

    public function withConnectTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withConnectTimeout($seconds);

        return $clone;
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $values) {
            $clone->headers[strtolower($name)] = [
                'name' => $name,
                'values' => \is_array($values) ? array_values($values) : [$values],
            ];
        }

        return $clone;
    }

    public function withBaseUri(UriInterface|string $uri): self
    {
        $uri = $uri instanceof UriInterface ? $uri : Uri::parse($uri);

        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            throw new InvalidArgumentException('Base URI must be absolute.');
        }

        $clone = clone $this;
        $clone->baseUri = $uri;

        return $clone;
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withHeaders([
            Header::AUTHORIZATION => 'Basic ' . base64_encode($username . ':' . $password),
        ]);
    }

    public function withBearerAuth(string $token): self
    {
        return $this->withHeaders([
            Header::AUTHORIZATION => 'Bearer ' . $token,
        ]);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->adapter->sendRequest(
            $this->applyHeaders(
                $this->applyBaseUri($request),
            ),
        );
    }

    private function applyBaseUri(RequestInterface $request): RequestInterface
    {
        if (!$this->baseUri instanceof \Psr\Http\Message\UriInterface) {
            return $request;
        }

        $uri = $request->getUri();

        if ($uri->getScheme() !== '' || $uri->getHost() !== '') {
            return $request;
        }

        return $request->withUri(
            $this->baseUri
                ->withPath($this->resolvePath($this->baseUri->getPath(), $uri->getPath()))
                ->withQuery($uri->getQuery())
                ->withFragment($uri->getFragment()),
        );
    }

    private function applyHeaders(RequestInterface $request): RequestInterface
    {
        foreach ($this->headers as $header) {
            if (!$request->hasHeader($header['name'])) {
                $request = $request->withHeader($header['name'], $header['values']);
            }
        }

        return $request;
    }

    private function resolvePath(string $basePath, string $path): string
    {
        if ($path === '') {
            return $basePath === '' ? '/' : $basePath;
        }

        if (str_starts_with($path, '/')) {
            return $this->removeDotSegments($path);
        }

        if ($basePath === '' || !str_ends_with($basePath, '/')) {
            $basePath .= '/';
        }

        return $this->removeDotSegments($basePath . $path);
    }

    private function removeDotSegments(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
