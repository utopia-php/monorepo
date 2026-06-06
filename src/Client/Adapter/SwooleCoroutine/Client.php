<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\SwooleCoroutine;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as SwooleClient;
use Throwable;
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

    private const string SETTING_CONNECT_TIMEOUT = 'connect_timeout';

    private const string SETTING_HTTP2 = 'http2';

    private const string SETTING_TIMEOUT = 'timeout';

    private readonly ResponseBuilder $responseBuilder;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory = new Response\Factory(),
        StreamFactoryInterface $streamFactory = new Stream\Factory(),
        private array $settings = [],
    ) {
        $this->settings += [
            self::SETTING_CONNECT_TIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
            self::SETTING_TIMEOUT => self::DEFAULT_TIMEOUT,
        ];

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
        return $this->perform($request, null);
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function streamRequest(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->perform($request, $sink);
    }

    /**
     * Execute the request. When $sink is given, Swoole's write callback forwards
     * each body chunk to it as data arrives, so the body is never fully held in
     * memory and the returned response has an empty body; otherwise the body is
     * buffered onto the response.
     *
     * @param (callable(string): void)|null $sink
     *
     * @throws ClientExceptionInterface
     */
    private function perform(RequestInterface $request, ?callable $sink): ResponseInterface
    {
        if (!\extension_loaded('swoole')) {
            throw new AdapterPreconditionException($request, 'The swoole extension is required.');
        }

        if (Coroutine::getCid() < 0) {
            throw new AdapterPreconditionException($request, 'Swoole coroutine HTTP requests must run inside a coroutine.');
        }

        $uri = $request->getUri();

        if (!\in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidUriException($request, 'Requests must use an absolute URI.');
        }

        $this->validateSettings();

        try {
            $client = new SwooleClient(
                $uri->getHost(),
                $this->port($request),
                $uri->getScheme() === 'https',
            );
        } catch (Throwable $throwable) {
            throw new AdapterInitializationException($request, $throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }

        $settings = $this->settings + [self::SETTING_HTTP2 => false];

        if ($sink !== null) {
            $settings['write_func'] = static function (SwooleClient $cli, string $chunk) use ($sink): void {
                unset($cli);
                $sink($chunk);
            };
        }

        try {
            if ($client->set($settings) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole client settings.');
            }

            if ($client->setMethod($request->getMethod()) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole request method.');
            }

            if ($client->setHeaders($this->requestHeaders($request)) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole request headers.');
            }

            $body = (string) $request->getBody();

            if ($body !== '' && $client->setData($body) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole request body.');
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            $client->close();

            throw $invalidArgumentException;
        } catch (Throwable $throwable) {
            $client->close();

            throw new InvalidArgumentException($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }

        try {
            $result = $client->execute($this->path($request));
        } catch (Throwable $throwable) {
            $client->close();

            throw $this->networkException($request, $throwable->getMessage(), (int) $throwable->getCode(), null, $throwable);
        }

        if ($result === false) {
            $message = \is_string($client->errMsg) && $client->errMsg !== '' ? $client->errMsg : 'Swoole request failed.';
            $code = \is_int($client->errCode) ? $client->errCode : 0;
            $statusCode = $client->statusCode;
            $client->close();

            if ($this->isTimeout($message, $code, $statusCode)) {
                throw new TimeoutException($request, $message, $code);
            }

            throw $this->networkException($request, $message, $code, $statusCode, null, $client->headers);
        }

        $statusCode = $client->statusCode;

        if (!\is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
            $client->close();

            throw new InvalidResponseException($request, 'Received an invalid HTTP response.');
        }

        $headers = $client->headers;

        if (!\is_array($headers)) {
            $headers = [];
        }

        $responseBody = $sink === null ? $client->body : '';

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

    private function validateSettings(): void
    {
        foreach ([self::SETTING_TIMEOUT, self::SETTING_CONNECT_TIMEOUT] as $setting) {
            if (!\array_key_exists($setting, $this->settings)) {
                continue;
            }

            $value = $this->settings[$setting];

            if (!\is_int($value) && !\is_float($value)) {
                throw new InvalidArgumentException('Swoole setting "' . $setting . '" must be a finite number greater than or equal to zero.');
            }

            if ($value < 0 || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Swoole setting "' . $setting . '" must be a finite number greater than or equal to zero.');
            }
        }

        if (\array_key_exists(self::SETTING_HTTP2, $this->settings) && !\is_bool($this->settings[self::SETTING_HTTP2])) {
            throw new InvalidArgumentException('Swoole setting "' . self::SETTING_HTTP2 . '" must be a boolean.');
        }
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

    private function isTimeout(string $message, int $code, mixed $statusCode): bool
    {
        unset($message);

        if ($this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT', -2)) {
            return true;
        }

        return \in_array($code, $this->nativeCodes([
            'SOCKET_ETIMEDOUT',
            'SWOOLE_ERROR_SOCKET_POLL_TIMEOUT',
        ], [110]), true);
    }

    private function networkException(RequestInterface $request, string $message, int $code, mixed $statusCode = null, ?Throwable $previous = null, mixed $headers = null): NetworkException
    {
        if ($this->isTimeout($message, $code, $statusCode)) {
            return new TimeoutException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_DNSLOOKUP_RESOLVE_FAILED',
            'SWOOLE_ERROR_DNSLOOKUP_RESOLVE_TIMEOUT',
            'SWOOLE_ERROR_DNSLOOKUP_NO_SERVER',
        ]), true)) {
            return new DnsException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_SSL_NOT_READY',
            'SWOOLE_ERROR_SSL_EMPTY_PEER_CERTIFICATE',
            'SWOOLE_ERROR_SSL_VERIFY_FAILED',
            'SWOOLE_ERROR_SSL_BAD_CLIENT',
            'SWOOLE_ERROR_SSL_BAD_PROTOCOL',
            'SWOOLE_ERROR_SSL_RESET',
            'SWOOLE_ERROR_SSL_HANDSHAKE_FAILED',
            'SWOOLE_ERROR_SSL_CREATE_CONTEXT_FAILED',
            'SWOOLE_ERROR_SSL_CREATE_SESSION_FAILED',
        ]), true)) {
            return new TlsException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_HTTP_PROXY_HANDSHAKE_ERROR',
            'SWOOLE_ERROR_HTTP_PROXY_HANDSHAKE_FAILED',
            'SWOOLE_ERROR_HTTP_PROXY_BAD_RESPONSE',
            'SWOOLE_ERROR_SOCKS5_UNSUPPORT_VERSION',
            'SWOOLE_ERROR_SOCKS5_UNSUPPORT_METHOD',
            'SWOOLE_ERROR_SOCKS5_AUTH_FAILED',
            'SWOOLE_ERROR_SOCKS5_SERVER_ERROR',
            'SWOOLE_ERROR_SOCKS5_HANDSHAKE_FAILED',
        ]), true)) {
            return new ProxyException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_PROTOCOL_ERROR',
            'SWOOLE_ERROR_HTTP_INVALID_PROTOCOL',
            'SWOOLE_ERROR_PACKAGE_MALFORMED_DATA',
            'SWOOLE_ERROR_HTTP2_STREAM_NO_HEADER',
            'SWOOLE_ERROR_HTTP2_SEND_CONTROL_FRAME_FAILED',
            'SWOOLE_ERROR_HTTP2_INTERNAL_ERROR',
        ]), true)) {
            return new ProtocolException($request, $message, $code, $previous);
        }

        if (\is_array($headers) && \in_array($code, $this->nativeCodes([
            'SOCKET_ECONNRESET',
        ], [104]), true)) {
            return new ProtocolException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SOCKET_EPIPE',
            'SOCKET_ENETUNREACH',
            'SOCKET_ECONNRESET',
            'SOCKET_ECONNREFUSED',
            'SOCKET_EHOSTUNREACH',
            'SWOOLE_ERROR_CLIENT_NO_CONNECTION',
            'SWOOLE_ERROR_SESSION_CLOSED_BY_SERVER',
            'SWOOLE_ERROR_SESSION_CLOSED_BY_CLIENT',
            'SWOOLE_ERROR_SESSION_CLOSED',
        ], [32, 101, 104, 111, 113]), true)) {
            return new ConnectionException($request, $message, $code, $previous);
        }

        if ($this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED', -1) || $this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET', -3) || $this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED', -4)) {
            return new ConnectionException($request, $message, $code, $previous);
        }

        return new NetworkException($request, $message, $code, $previous);
    }

    /**
     * @param array<int, string> $names
     * @param array<int, int> $fallbacks
     *
     * @return array<int, int>
     */
    private function nativeCodes(array $names, array $fallbacks = []): array
    {
        $codes = $fallbacks;

        foreach ($names as $name) {
            if (!\defined($name)) {
                continue;
            }

            $code = \constant($name);

            if (\is_int($code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function statusCodeIs(mixed $statusCode, string $constant, int $fallback): bool
    {
        if (!\is_int($statusCode)) {
            return false;
        }

        if (\defined($constant) && \constant($constant) === $statusCode) {
            return true;
        }

        return $statusCode === $fallback;
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
