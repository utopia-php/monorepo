<?php

declare(strict_types=1);

namespace Nats\Transport;

use Nats\Exception\ConnectionException;
use Nats\Exception\TimeoutException;

final class TcpTransport implements Transport
{
    /** @var resource|null */
    private $stream = null;

    public function connect(string $host, int $port, float $timeout): void
    {
        $address = "tcp://{$host}:{$port}";
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new ConnectionException("Failed to connect to {$address}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($stream, true);
        $this->setTimeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function write(string $data): int
    {
        $stream = $this->ensureConnected();
        $written = @fwrite($stream, $data);

        if ($written === false) {
            throw new ConnectionException('Failed to write to socket');
        }

        return $written;
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        $stream = $this->ensureConnected();

        if ($timeout !== null) {
            $this->setTimeout($stream, $timeout);
        }

        $data = @fread($stream, $maxBytes);

        if ($data === false) {
            if ($this->isTimedOut($stream)) {
                throw new TimeoutException('Read timed out');
            }
            throw new ConnectionException('Failed to read from socket');
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
            $this->setTimeout($stream, $timeout);
        }

        $line = @fgets($stream);

        if ($line === false) {
            if ($this->isTimedOut($stream)) {
                throw new TimeoutException('Read timed out');
            }
            if (feof($stream)) {
                throw new ConnectionException('Connection closed by server');
            }
            throw new ConnectionException('Failed to read line from socket');
        }

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        $stream = $this->ensureConnected();

        $contextOptions = ['ssl' => []];

        if (isset($options['cafile'])) {
            $contextOptions['ssl']['cafile'] = $options['cafile'];
        }
        if (isset($options['local_cert'])) {
            $contextOptions['ssl']['local_cert'] = $options['local_cert'];
        }
        if (isset($options['local_pk'])) {
            $contextOptions['ssl']['local_pk'] = $options['local_pk'];
        }
        if (isset($options['verify_peer'])) {
            $contextOptions['ssl']['verify_peer'] = $options['verify_peer'];
        } else {
            $contextOptions['ssl']['verify_peer'] = true;
        }
        if (isset($options['verify_peer_name'])) {
            $contextOptions['ssl']['verify_peer_name'] = $options['verify_peer_name'];
        } else {
            $contextOptions['ssl']['verify_peer_name'] = true;
        }

        $context = stream_context_get_options($stream);
        $merged = array_merge_recursive($context, $contextOptions);
        foreach ($merged as $wrapper => $opts) {
            foreach ($opts as $key => $value) {
                stream_context_set_option($stream, $wrapper, $key, is_array($value) ? end($value) : $value);
            }
        }

        $result = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);

        if ($result !== true) {
            throw new ConnectionException('Failed to upgrade connection to TLS');
        }
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

    /** @return resource */
    private function ensureConnected()
    {
        if ($this->stream === null) {
            throw new ConnectionException('Not connected');
        }

        return $this->stream;
    }

    /** @param resource $stream */
    private function setTimeout($stream, float $timeout): void
    {
        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $microseconds);
    }

    /** @param resource $stream */
    private function isTimedOut($stream): bool
    {
        $info = stream_get_meta_data($stream);
        return $info['timed_out'] ?? false;
    }
}
