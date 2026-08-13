<?php

declare(strict_types=1);

namespace Utopia\SMTP\Transport;

use Swoole\Coroutine\Client;
use Utopia\SMTP\ConnectionException;
use Utopia\SMTP\Tls;

/**
 * Coroutine-native. recv() and send() yield the scheduler on their own, so this
 * works even where the runtime hook for streams is switched off.
 *
 * Requires ext-swoole, which the package only suggests.
 */
class Swoole implements Transport
{
    private ?Client $client = null;

    private bool $tls = false;

    /** Bytes taken off the socket but not yet handed out. */
    private string $buffer = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly Tls $options = new Tls(),
    ) {}

    public function connect(float $timeout, bool $tls): void
    {
        $client = new Client(SWOOLE_SOCK_TCP | ($tls ? SWOOLE_SSL : 0));
        $client->set($this->settings($timeout));

        if (! $client->connect($this->host, $this->port, $timeout)) {
            throw new ConnectionException(
                "Cannot reach {$this->host}:{$this->port}: [{$client->errCode}] {$client->errMsg}",
                $client->errCode,
            );
        }

        $this->client = $client;
        $this->tls = $tls;
        $this->buffer = '';
    }

    public function read(int $length, float $timeout): string
    {
        if ($this->buffer === '') {
            $client = $this->client();
            $data = $client->recv($timeout);

            if ($data === false || $data === '') {
                throw new ConnectionException(
                    $client->errCode === SOCKET_ETIMEDOUT
                        ? 'Timed out waiting for the server'
                        : "The server closed the connection: [{$client->errCode}] {$client->errMsg}",
                );
            }

            $this->buffer = $data;
        }

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    public function write(string $data, float $timeout): void
    {
        $client = $this->client();

        for ($written = 0; $written < \strlen($data);) {
            $sent = $client->send(substr($data, $written));

            if ($sent === false || $sent === 0) {
                throw new ConnectionException("Failed writing to the server: [{$client->errCode}] {$client->errMsg}");
            }

            $written += $sent;
        }
    }

    public function startTls(float $timeout): void
    {
        $client = $this->client();

        if (! $client->enableSSL()) {
            throw new ConnectionException(
                "STARTTLS handshake with {$this->host} failed: [{$client->errCode}] {$client->errMsg}",
            );
        }

        $this->tls = true;
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function close(): void
    {
        if ($this->client instanceof \Swoole\Coroutine\Client) {
            $this->client->close();
            $this->client = null;
            $this->tls = false;
            $this->buffer = '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(float $timeout): array
    {
        $settings = [
            'timeout' => $timeout,
            'ssl_verify_peer' => $this->options->verifyPeer,
            'ssl_allow_self_signed' => ! $this->options->verifyPeer,
            'ssl_host_name' => $this->options->peerName ?? $this->host,
        ];

        if ($this->options->caFile !== null) {
            $settings['ssl_cafile'] = $this->options->caFile;
        }

        if ($this->options->ciphers !== null) {
            $settings['ssl_ciphers'] = $this->options->ciphers;
        }

        return $settings;
    }

    private function client(): Client
    {
        if (!$this->client instanceof \Swoole\Coroutine\Client) {
            throw new ConnectionException('The transport is not connected');
        }

        return $this->client;
    }
}
