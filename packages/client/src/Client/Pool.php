<?php

declare(strict_types=1);

namespace Utopia\Client;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr18\StreamingClientInterface;

/**
 * A client that borrows a pooled client for the duration of each request and
 * reclaims it once the request completes, so concurrent callers share a bounded
 * set of underlying connections. The pool's resources must themselves be both a
 * PSR-18 and a streaming client.
 *
 * Per-request Options are forwarded to the borrowed client for that transfer
 * only — its configured defaults and reused connection stay untouched — and
 * require the pooled clients to implement Adapter.
 *
 * @template T of ClientInterface&StreamingClientInterface
 */
final readonly class Pool implements ClientInterface, StreamingClientInterface
{
    /**
     * @param Connections<T> $connections
     */
    public function __construct(
        private Connections $connections,
    ) {}

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request, ?Options $options = null): ResponseInterface
    {
        return $this->connections->use(
            fn(ClientInterface $client): ResponseInterface => $options instanceof Options
                ? $this->configurable($client)->sendRequest($request, $options)
                : $client->sendRequest($request),
        );
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink, ?Options $options = null): ResponseInterface
    {
        return $this->connections->use(
            fn(StreamingClientInterface $client): ResponseInterface => $options instanceof Options
                ? $this->configurable($client)->stream($request, $sink, $options)
                : $client->stream($request, $sink),
        );
    }

    /**
     * A plain PSR-18 client would silently ignore the extra argument, so demand
     * the Adapter contract before forwarding per-request options.
     */
    private function configurable(ClientInterface|StreamingClientInterface $client): Adapter
    {
        if (!$client instanceof Adapter) {
            throw new InvalidArgumentException(\sprintf('Per-request options require the pooled clients to implement %s.', Adapter::class));
        }

        return $client;
    }
}
