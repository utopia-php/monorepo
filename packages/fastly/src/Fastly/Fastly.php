<?php

declare(strict_types=1);

namespace Utopia\Fastly;

use Psr\Http\Client\ClientInterface;
use Utopia\Fastly\Exception\PurgeException;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Fastly HTTP API client. Currently exposes surrogate-key purging.
 *
 * Transport is any PSR-18 client — pass a configured `Utopia\Client` (its
 * cURL or Swoole adapter) so timeouts, TLS and pooling are the caller's choice.
 */
final readonly class Fastly
{
    private RequestFactory $requests;

    public function __construct(
        private ClientInterface $client,
        private string $serviceId,
        private string $token,
        private string $endpoint = 'https://api.fastly.com/service',
        private string $tokenHeader = 'Fastly-Key',
    ) {
        $this->requests = new RequestFactory();
    }

    /**
     * Purge every response tagged with a surrogate key.
     *
     * Network failures surface as the underlying PSR-18 client exceptions; a
     * non-2xx response from Fastly is reported as a PurgeException.
     *
     * @throws PurgeException When Fastly does not acknowledge the purge.
     */
    public function purge(string $surrogateKey): void
    {
        $url = rtrim($this->endpoint, '/')
            . '/' . rawurlencode($this->serviceId)
            . '/purge/' . rawurlencode($surrogateKey);

        $request = $this->requests->createRequest(Method::POST, $url)
            ->withHeader($this->tokenHeader, $this->token)
            ->withHeader('Accept', 'application/json');

        $response = $this->client->sendRequest($request);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new PurgeException($surrogateKey, $status, (string) $response->getBody());
        }
    }
}
