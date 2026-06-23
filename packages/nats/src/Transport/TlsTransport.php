<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;

final class TlsTransport implements Transport
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly array $tlsOptions = [],
    ) {}

    public function connect(string $host, int $port, float $timeout): void
    {
        $address = "tls://{$host}:{$port}";
        $errno = 0;
        $errstr = '';

        $context = stream_context_create(['ssl' => $this->buildSslOptions()]);

        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new ConnectionException("Failed to connect to {$address}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($stream, true);
        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $microseconds);

        $this->stream = $stream;
    }

    public function write(string $data): int
    {
        $stream = $this->ensureConnected();
        $written = @fwrite($stream, $data);

        if ($written === false) {
            throw new ConnectionException('Failed to write to TLS socket');
        }

        return $written;
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        $stream = $this->ensureConnected();

        if ($timeout !== null) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            stream_set_timeout($stream, $seconds, $microseconds);
        }

        $data = @fread($stream, $maxBytes);

        if ($data === false) {
            $info = stream_get_meta_data($stream);
            if ($info['timed_out']) {
                throw new TimeoutException('Read timed out');
            }
            throw new ConnectionException('Failed to read from TLS socket');
        }

        if ($data === '' && feof($stream)) {
            throw new ConnectionException('Connection closed by server');
        }

        return $data;
    }

    public function readLine(?float $timeout = null): string
    {
        $stream = $this->ensureConnected();

        if ($timeout !== null) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            stream_set_timeout($stream, $seconds, $microseconds);
        }

        $line = @fgets($stream);

        if ($line === false) {
            $info = stream_get_meta_data($stream);
            if ($info['timed_out']) {
                throw new TimeoutException('Read timed out');
            }
            if (feof($stream)) {
                throw new ConnectionException('Connection closed by server');
            }
            throw new ConnectionException('Failed to read line from TLS socket');
        }

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        // Already TLS, nothing to do
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    private function buildSslOptions(): array
    {
        $opts = [
            'verify_peer' => $this->tlsOptions['verify_peer'] ?? true,
            'verify_peer_name' => $this->tlsOptions['verify_peer_name'] ?? true,
        ];

        if (isset($this->tlsOptions['cafile'])) {
            $opts['cafile'] = $this->tlsOptions['cafile'];
        }
        if (isset($this->tlsOptions['local_cert'])) {
            $opts['local_cert'] = $this->tlsOptions['local_cert'];
        }
        if (isset($this->tlsOptions['local_pk'])) {
            $opts['local_pk'] = $this->tlsOptions['local_pk'];
        }

        return $opts;
    }

    /** @return resource */
    private function ensureConnected()
    {
        if ($this->stream === null) {
            throw new ConnectionException('Not connected');
        }

        return $this->stream;
    }
}
