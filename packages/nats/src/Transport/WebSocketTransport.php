<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;

/**
 * WebSocket transport (RFC 6455) for NATS over ws:// or wss://.
 *
 * The NATS protocol runs inside binary WebSocket frames. Client-to-server frames
 * are masked as the spec requires; server-to-client frames are not. read()/readLine()
 * serve the client the un-framed NATS byte stream from an internal buffer.
 */
final class WebSocketTransport implements Transport
{
    /** @var resource|null */
    private $stream;

    // Decoded (un-framed) NATS bytes not yet consumed by read()/readLine().
    private string $buffer = '';

    /**
     * @param bool $secure use wss:// (TLS) instead of ws://
     * @param array<string,mixed> $tlsOptions ssl context options when $secure
     * @param string $path HTTP request path for the upgrade (NATS default "/")
     */
    public function __construct(
        private readonly bool $secure = false,
        private readonly array $tlsOptions = [],
        private readonly string $path = '/',
    ) {}

    public function connect(string $host, int $port, float $timeout): void
    {
        $scheme = $this->secure ? 'tls' : 'tcp';
        $context = stream_context_create($this->secure ? ['ssl' => $this->tlsOptions] : []);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new ConnectionException("Failed to connect to {$host}:{$port}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($stream, true);
        $this->setTimeout($stream, $timeout);
        $this->stream = $stream;

        $this->handshake($host, $port);
    }

    public function write(string $data): int
    {
        $this->writeFrame($data);

        return \strlen($data);
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        if ($timeout !== null) {
            $this->setTimeout($this->ensureConnected(), $timeout);
        }

        while ($this->buffer === '') {
            $this->readFrame();
        }

        $chunk = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    public function readLine(?float $timeout = null): string
    {
        if ($timeout !== null) {
            $this->setTimeout($this->ensureConnected(), $timeout);
        }

        while (($pos = strpos($this->buffer, "\n")) === false) {
            $this->readFrame();
        }

        $line = substr($this->buffer, 0, $pos + 1);
        $this->buffer = substr($this->buffer, $pos + 1);

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        // wss:// negotiates TLS at connect; there is no in-band STARTTLS for WebSocket.
        throw new ConnectionException('STARTTLS is not supported over WebSocket; use wss://');
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

    private function handshake(string $host, int $port): void
    {
        $key = base64_encode(random_bytes(16));
        $request = "GET {$this->path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        $this->rawWrite($request);

        // Read the HTTP response headers up to the blank line, keeping any trailing
        // bytes (the first WS frame) — they must not be swallowed.
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $response .= $this->rawRead(1);
        }

        if (!preg_match('#^HTTP/1\.1 101#i', $response)) {
            $status = strtok($response, "\r\n");
            throw new ConnectionException("WebSocket upgrade failed: {$status}");
        }

        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('#Sec-WebSocket-Accept:\s*(.+?)\r\n#i', $response, $m) || trim($m[1]) !== $expected) {
            throw new ConnectionException('WebSocket upgrade failed: bad Sec-WebSocket-Accept');
        }
    }

    /**
     * Read one WebSocket frame, handling control frames, and append data payloads
     * (text/binary/continuation) to the buffer.
     */
    private function readFrame(): void
    {
        $header = $this->rawRead(2);
        $b0 = \ord($header[0]);
        $b1 = \ord($header[1]);

        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $len = $b1 & 0x7F;

        if ($len === 126) {
            $ext = $this->rawRead(2);
            $len = (\ord($ext[0]) << 8) | \ord($ext[1]);
        } elseif ($len === 127) {
            $ext = $this->rawRead(8);
            $len = 0;
            for ($i = 0; $i < 8; $i++) {
                $len = ($len << 8) | \ord($ext[$i]);
            }
        }

        $maskKey = $masked ? $this->rawRead(4) : '';
        $payload = $len > 0 ? $this->rawRead($len) : '';

        if ($masked && $payload !== '') {
            $unmasked = '';
            for ($i = 0, $n = \strlen($payload); $i < $n; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        switch ($opcode) {
            case 0x0: // continuation
            case 0x1: // text
            case 0x2: // binary
                $this->buffer .= $payload;
                return;
            case 0x8: // close
                throw new ConnectionException('WebSocket closed by server');
            case 0x9: // ping -> pong
                $this->writeFrame($payload, 0xA);
                return;
            case 0xA: // pong
                return;
            default:
                throw new ConnectionException("Unexpected WebSocket opcode: {$opcode}");
        }
    }

    private function writeFrame(string $payload, int $opcode = 0x2): void
    {
        $len = \strlen($payload);
        $frame = \chr(0x80 | $opcode); // FIN + opcode

        if ($len < 126) {
            $frame .= \chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $frame .= \chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= \chr(0x80 | 127) . pack('J', $len);
        }

        $maskKey = random_bytes(4);
        $frame .= $maskKey;

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $maskKey[$i % 4];
        }

        $this->rawWrite($frame . $masked);
    }

    private function rawWrite(string $data): void
    {
        $stream = $this->ensureConnected();
        $total = \strlen($data);
        $written = 0;

        while ($written < $total) {
            $chunk = @fwrite($stream, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new ConnectionException('Failed to write to WebSocket');
            }
            $written += $chunk;
        }
    }

    private function rawRead(int $length): string
    {
        $stream = $this->ensureConnected();
        $data = '';

        while (\strlen($data) < $length) {
            $chunk = @fread($stream, $length - \strlen($data));
            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($stream);
                if ($info['timed_out']) {
                    throw new TimeoutException('Read timed out');
                }
                throw new ConnectionException('Connection closed by server');
            }
            $data .= $chunk;
        }

        return $data;
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
}
