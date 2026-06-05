<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

final readonly class RequestFactory implements RequestFactoryInterface
{
    public function __construct(
        private UriFactoryInterface $uriFactory = new UriFactory(),
    ) {}

    public function createRequest(string $method, $uri): RequestInterface
    {
        $uri = $uri instanceof UriInterface ? $uri : $this->uriFactory->createUri((string) $uri);

        return new Request(strtoupper($method), $uri)
            ->withUri($uri);
    }
}
