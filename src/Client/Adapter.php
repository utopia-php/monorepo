<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface Adapter extends ClientInterface
{
    public function withTimeout(float $seconds): static;

    public function withConnectTimeout(float $seconds): static;

    /**
     * Send a request and pass each response body chunk to $sink as it arrives,
     * keeping memory bounded regardless of body size. The returned response
     * carries the status and headers; its body is empty because the body was
     * delivered to $sink.
     *
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function streamRequest(RequestInterface $request, callable $sink): ResponseInterface;
}
