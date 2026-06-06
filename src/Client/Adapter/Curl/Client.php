<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\Curl;

use CurlHandle;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Exception\AdapterInitializationException;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\InvalidResponseException;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\ProtocolException;
use Utopia\Client\Exception\ProxyException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Response\Builder as ResponseBuilder;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use ValueError;

class Client implements Adapter
{
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;

    private const float DEFAULT_TIMEOUT = 30.0;

    private readonly ResponseBuilder $responseBuilder;

    /**
     * Native cURL options. Values override adapter defaults when keys overlap.
     *
     * @param array<int, mixed> $options
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory = new Response\Factory(),
        StreamFactoryInterface $streamFactory = new Stream\Factory(),
        private array $options = [],
    ) {
        $this->options += [
            \CURLOPT_CONNECTTIMEOUT_MS => $this->milliseconds(self::DEFAULT_CONNECT_TIMEOUT),
            \CURLOPT_TIMEOUT_MS => $this->milliseconds(self::DEFAULT_TIMEOUT),
        ];

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
            throw new AdapterPreconditionException($request, 'The curl extension is required.');
        }

        $uri = $request->getUri();
        $url = (string) $uri;

        if (!\in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidUriException($request, 'Requests must use an absolute URI.');
        }

        $headers = '';
        $body = '';
        $handle = curl_init($url);

        if (!$handle instanceof CurlHandle) {
            throw new AdapterInitializationException($request, 'Unable to initialize curl.');
        }

        $options = $this->options($request, $headers, $body);

        try {
            if (curl_setopt_array($handle, $options) === false) {
                throw new InvalidArgumentException('Unable to configure curl.');
            }

            $result = curl_exec($handle);
        } catch (ValueError $valueError) {
            throw new InvalidArgumentException($valueError->getMessage(), 0, $valueError);
        }

        if ($result === false) {
            $message = curl_error($handle);
            $code = curl_errno($handle);

            throw $this->networkException($request, $message === '' ? 'Curl request failed.' : $message, $code);
        }

        if (!preg_match("/\r\n\r\n|\n\n|\r\r/", $headers)) {
            throw new ConnectionException($request, 'Connection closed before a complete HTTP response was received.');
        }

        $parsed = $this->parseHeaderBlock($headers);

        if ($parsed['status'] < 100 || $parsed['status'] > 599) {
            throw new InvalidResponseException($request, 'Received an invalid HTTP response.');
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

    private function networkException(RequestInterface $request, string $message, int $code): NetworkException
    {
        if ($code === \CURLE_OPERATION_TIMEDOUT) {
            return new TimeoutException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_COULDNT_RESOLVE_HOST',
            'CURLE_COULDNT_RESOLVE_PROXY',
        ]), true)) {
            return new DnsException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_PROXY',
            'CURLE_HTTP_PROXYTUNNEL',
        ]), true)) {
            return new ProxyException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CACERT',
            'CURLE_SSL_PEER_CERTIFICATE',
            'CURLE_SSL_CACERT_BADFILE',
            'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER',
            'CURLE_SSL_ENGINE_NOTFOUND',
            'CURLE_SSL_ENGINE_SETFAILED',
            'CURLE_SSL_ENGINE_INITFAILED',
            'CURLE_USE_SSL_FAILED',
            'CURLE_SSL_SHUTDOWN_FAILED',
            'CURLE_SSL_CRL_BADFILE',
            'CURLE_SSL_ISSUER_ERROR',
            'CURLE_SSL_PINNEDPUBKEYNOTMATCH',
            'CURLE_SSL_INVALIDCERTSTATUS',
            'CURLE_SSL_CLIENTCERT',
            'CURLE_ECH_REQUIRED',
        ]), true)) {
            return new TlsException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_UNSUPPORTED_PROTOCOL',
            'CURLE_HTTP2',
            'CURLE_HTTP2_STREAM',
            'CURLE_HTTP3',
            'CURLE_QUIC_CONNECT_ERROR',
            'CURLE_WEIRD_SERVER_REPLY',
            'CURLE_BAD_CONTENT_ENCODING',
            'CURLE_CHUNK_FAILED',
            'CURLE_TOO_MANY_REDIRECTS',
            'CURLE_PARTIAL_FILE',
        ]), true)) {
            return new ProtocolException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_COULDNT_CONNECT',
            'CURLE_SEND_ERROR',
            'CURLE_RECV_ERROR',
            'CURLE_GOT_NOTHING',
            'CURLE_INTERFACE_FAILED',
            'CURLE_NO_CONNECTION_AVAILABLE',
            'CURLE_UNRECOVERABLE_POLL',
            'CURLE_AGAIN',
        ]), true)) {
            return new ConnectionException($request, $message, $code);
        }

        return new NetworkException($request, $message, $code);
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<int, int>
     */
    private function curlCodes(array $names): array
    {
        $codes = [];

        foreach ($names as $name) {
            if (\defined($name)) {
                $code = \constant($name);

                if (\is_int($code)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
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
