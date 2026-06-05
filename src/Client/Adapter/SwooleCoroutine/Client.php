<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\SwooleCoroutine;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as SwooleClient;
use Throwable;
use Utopia\Client\Adapter;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\RequestException;
use Utopia\Client\Response\Builder as ResponseBuilder;
use ValueError;

class Client implements Adapter
{
    private const string SETTING_CONNECT_TIMEOUT = 'connect_timeout';

    private const string SETTING_HTTP2 = 'http2';

    private const string SETTING_TIMEOUT = 'timeout';

    private readonly ResponseBuilder $responseBuilder;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        private array $settings = [],
    ) {
        $this->responseBuilder = new ResponseBuilder($responseFactory, $streamFactory);
    }

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_TIMEOUT] = $this->seconds($seconds);

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_CONNECT_TIMEOUT] = $this->seconds($seconds);

        return $clone;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!\extension_loaded('swoole')) {
            throw new RequestException($request, 'The swoole extension is required.');
        }

        if (Coroutine::getCid() < 0) {
            throw new RequestException($request, 'Swoole coroutine HTTP requests must run inside a coroutine.');
        }

        $uri = $request->getUri();

        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            throw new RequestException($request, 'Requests must use an absolute URI.');
        }

        $client = new SwooleClient(
            $uri->getHost(),
            $this->port($request),
            $uri->getScheme() === 'https',
        );

        $client->set($this->settings + [
            self::SETTING_HTTP2 => false,
        ]);

        $client->setMethod($request->getMethod());
        $client->setHeaders($this->requestHeaders($request));

        $body = (string) $request->getBody();

        if ($body !== '') {
            $client->setData($body);
        }

        try {
            $result = $client->execute($this->path($request));
        } catch (Throwable $throwable) {
            $client->close();

            throw new NetworkException($request, $throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }

        if ($result === false) {
            $message = \is_string($client->errMsg) && $client->errMsg !== '' ? $client->errMsg : 'Swoole request failed.';
            $code = \is_int($client->errCode) ? $client->errCode : 0;
            $client->close();

            throw new NetworkException($request, $message, $code);
        }

        $statusCode = $client->statusCode;

        if (!\is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
            $client->close();

            throw new RequestException($request, 'Received an invalid HTTP response.');
        }

        $headers = $client->headers;

        if (!\is_array($headers)) {
            $headers = [];
        }

        $responseBody = $client->body;

        if (!\is_string($responseBody)) {
            $responseBody = '';
        }

        $response = $this->responseBuilder->build(
            $statusCode,
            '',
            $this->headers($headers),
            $responseBody,
        );

        $client->close();

        return $response;
    }

    private function port(RequestInterface $request): int
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        if ($port !== null) {
            return $port;
        }

        return $uri->getScheme() === 'https' ? 443 : 80;
    }

    private function seconds(float $seconds): float
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        return $seconds;
    }

    private function path(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $query = $uri->getQuery();

        if ($query === '') {
            return $path;
        }

        return $path . '?' . $query;
    }

    /**
     * @return array<array-key, string>
     */
    private function requestHeaders(RequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        if (!$request->hasHeader('Host') && $request->getUri()->getHost() !== '') {
            $headers['Host'] = $this->host($request);
        }

        return $headers;
    }

    private function host(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        if ($port === null) {
            return $uri->getHost();
        }

        return $uri->getHost() . ':' . $port;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, array<int, string>>
     */
    private function headers(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }

            if (\is_string($value)) {
                $normalized[$name] = [$value];

                continue;
            }

            if (!\is_array($value)) {
                continue;
            }

            foreach ($value as $singleValue) {
                if (\is_string($singleValue)) {
                    $normalized[$name][] = $singleValue;
                }
            }
        }

        return $normalized;
    }
}
