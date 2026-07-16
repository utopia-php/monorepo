<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr18\StreamingClientInterface;

/**
 * A transport that can both buffer (PSR-18) and stream responses, configured
 * through immutable withX helpers. Decorators implement this too, so they compose.
 *
 * Both send methods accept per-request Options that override the configured
 * transport settings for that transfer only, leaving the adapter's defaults and
 * any reused connection untouched.
 */
interface Adapter extends ClientInterface, StreamingClientInterface
{
    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request, ?Options $options = null): ResponseInterface;

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink, ?Options $options = null): ResponseInterface;

    public function withTimeout(float $seconds): static;

    public function withConnectTimeout(float $seconds): static;

    public function withSslVerification(bool $enabled = true): static;

    public function withCustomCA(string $path): static;

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static;

    public function withMinTlsVersion(Tls $version): static;

    /**
     * Reuse the underlying connection across requests to the same origin so the
     * TCP/TLS handshake is paid once, rather than dialling afresh every request.
     * Off by default. Each adapter maps this to its own transport: a persisted
     * cURL handle, a kept-alive Swoole client, and so on.
     */
    public function withConnectionReuse(bool $enabled = true): static;
}
