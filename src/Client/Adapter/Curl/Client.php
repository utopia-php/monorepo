<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\Curl;

use CurlHandle;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\RequestException;
use Utopia\Client\Response\Builder as ResponseBuilder;
use ValueError;

class Client implements Adapter
{
    private readonly ResponseBuilder $responseBuilder;

    /**
     * Native cURL options. Values override adapter defaults when keys overlap.
     *
     * @param array<int, mixed> $options
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        private array $options = [],
    ) {
        $this->responseBuilder = new ResponseBuilder($responseFactory, $streamFactory);
    }

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_TIMEOUT_MS] = $this->milliseconds($seconds);

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_CONNECTTIMEOUT_MS] = $this->milliseconds($seconds);

        return $clone;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!\extension_loaded('curl')) {
            throw new RequestException($request, 'The curl extension is required.');
        }

        $uri = $request->getUri();
        $url = (string) $uri;

        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            throw new RequestException($request, 'Requests must use an absolute URI.');
        }

        $headers = '';
        $body = '';
        $handle = curl_init($url);

        if (!$handle instanceof CurlHandle) {
            throw new RequestException($request, 'Unable to initialize curl.');
        }

        $options = $this->options($request, $headers, $body);

        try {
            curl_setopt_array($handle, $options);
            $result = curl_exec($handle);
        } catch (ValueError $valueError) {
            throw new RequestException($request, $valueError->getMessage(), 0, $valueError);
        }

        if ($result === false) {
            $message = curl_error($handle);
            $code = curl_errno($handle);

            throw new NetworkException($request, $message === '' ? 'Curl request failed.' : $message, $code);
        }

        $parsed = $this->parseHeaderBlock($headers);

        if ($parsed['status'] < 100 || $parsed['status'] > 599) {
            throw new RequestException($request, 'Received an invalid HTTP response.');
        }

        return $this->responseBuilder->build(
            $parsed['status'],
            $parsed['reason'],
            $parsed['headers'],
            $body,
            $parsed['protocol'],
        );
    }

    /**
     * @param-out string $headers
     * @param-out string $body
     *
     * @return array<int, mixed>
     */
    private function options(RequestInterface $request, string &$headers, string &$body): array
    {
        $options = [
            \CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_HEADER => false,
            \CURLOPT_HTTPHEADER => $this->headers($request),
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
            \CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                unset($handle);
                $headers .= $line;

                return \strlen($line);
            },
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body): int {
                unset($handle);
                $body .= $chunk;

                return \strlen($chunk);
            },
        ];

        $requestBody = (string) $request->getBody();

        if ($requestBody !== '') {
            $options[\CURLOPT_POSTFIELDS] = $requestBody;
        }

        return $this->options + $options;
    }

    private function milliseconds(float $seconds): int
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        return (int) round($seconds * 1000);
    }

    /**
     * @return array<int, string>
     */
    private function headers(RequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        if (!$request->hasHeader('Host') && $request->getUri()->getHost() !== '') {
            $headers[] = 'Host: ' . $this->host($request);
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
     * @return array{protocol: string, status: int, reason: string, headers: array<string, array<int, string>>}
     */
    private function parseHeaderBlock(string $headerBlock): array
    {
        $blocks = preg_split("/\r\n\r\n|\n\n|\r\r/", trim($headerBlock));
        $headers = $blocks === false ? [] : array_values(array_filter($blocks));
        $header = $headers === [] ? '' : $headers[array_key_last($headers)];
        $lines = preg_split("/\r\n|\n|\r/", $header);
        $lines = $lines === false ? [] : $lines;

        $statusLine = array_shift($lines) ?? '';

        $protocol = '1.1';
        $status = 0;
        $reason = '';

        if (preg_match('/^HTTP\/(?P<protocol>\d+(?:\.\d+)?)\s+(?P<status>\d{3})(?:\s+(?P<reason>.*))?$/i', $statusLine, $matches) === 1) {
            $protocol = $matches['protocol'];
            $status = (int) $matches['status'];
            $reason = $matches['reason'] ?? '';
        }

        $parsedHeaders = [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $parsedHeaders[$name][] = trim($value);
        }

        return [
            'protocol' => $protocol,
            'status' => $status,
            'reason' => $reason,
            'headers' => $parsedHeaders,
        ];
    }
}
