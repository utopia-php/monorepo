<?php

declare(strict_types=1);

namespace Utopia;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;

final class Client implements ClientInterface
{
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
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->adapter->sendRequest($request);
    }
}
