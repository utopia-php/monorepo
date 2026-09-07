<?php

namespace Utopia\WebSocket\Adapter;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Utopia\WebSocket\Adapter;

class Swoole extends Adapter
{
    /**
     * Bytes the reactor may buffer per connection for a client that is not keeping up.
     */
    public const DEFAULT_SOCKET_BUFFER_SIZE = 524288;

    protected Server $server;

    protected string $host;

    protected int $port;

    /**
     * @param int $socketBufferSize Per-connection output buffer limit in bytes.
     *   Pass 0 to retain Swoole's default buffer size and send_yield behavior.
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 80,
        int $socketBufferSize = self::DEFAULT_SOCKET_BUFFER_SIZE,
    ) {
        parent::__construct($host, $port);

        $this->server = new Server($this->host, $this->port);

        // Set maximum connections to Swoole's limit of 1 Million
        $this->config['max_connection'] = 1_000_000;

        if ($socketBufferSize > 0) {
            // The listen port captures its buffer size at construction. Setting
            // socket_buffer_size on the server later does not update that copy.
            $this->server->ports[0]->set(['socket_buffer_size' => $socketBufferSize]);
            // Yielding on a full buffer would accumulate pending sends in the
            // worker instead of allowing send() to disconnect the slow client.
            $this->config['send_yield'] = false;
        }
    }

    public function start(): void
    {
        $this->server->set($this->config);
        $this->server->start();
    }

    public function shutdown(): void
    {
        $this->server->shutdown();
    }

    public function send(array $connections, string $message): void
    {
        $flags = SWOOLE_WEBSOCKET_FLAG_FIN;
        if ($this->config['websocket_compression'] ?? false) {
            $flags |= SWOOLE_WEBSOCKET_FLAG_COMPRESS;
        }

        foreach ($connections as $connection) {
            go(function () use ($connection, $message, $flags): void {
                if (!$this->server->exist($connection) || !$this->server->isEstablished($connection)) {
                    $this->server->close($connection);

                    return;
                }

                $pushed = $this->server->push(
                    $connection,
                    $message,
                    SWOOLE_WEBSOCKET_OPCODE_TEXT,
                    $flags,
                );

                // A failed push loses a message. Disconnect so the client can
                // reconnect and resynchronize instead of remaining out of sync.
                if (!$pushed) {
                    $this->server->close($connection);
                }
            });
        }
    }

    public function close(int $connection, int $code): void
    {
        $this->server->close($connection);
    }

    public function onStart(callable $callback): self
    {
        $this->server->on('start', function () use ($callback): void {
            \call_user_func($callback);

            Process::signal('2', function (): void {
                $this->shutdown();
            });
        });

        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->server->on('workerStart', function (Server $server, int $workerId) use ($callback): void {
            \call_user_func($callback, $workerId);
        });
        return $this;
    }

    public function onWorkerStop(callable $callback): Adapter
    {
        $this->server->on('workerStop', function (Server $server, int $workerId) use ($callback): void {
            \call_user_func($callback, $workerId);
        });

        return $this;
    }

    public function onOpen(callable $callback): self
    {
        $this->server->on('open', function (Server $server, Request $request) use ($callback): void {
            \call_user_func($callback, $request->fd, $request);
        });

        return $this;
    }

    public function onMessage(callable $callback): self
    {
        $this->server->on('message', function (Server $server, Frame $frame) use ($callback): void {
            \call_user_func($callback, $frame->fd, $frame->data);
        });

        return $this;
    }

    public function onClose(callable $callback): self
    {
        $this->server->on('close', function (Server $server, int $fd) use ($callback): void {
            \call_user_func($callback, $fd);
        });

        return $this;
    }

    public function onRequest(callable $callback): self
    {
        $this->server->on('request', function (Request $request, Response $response) use ($callback): void {
            \call_user_func($callback, $request, $response);
        });

        return $this;
    }

    public function setPackageMaxLength(int $bytes): self
    {
        $this->config['package_max_length'] = $bytes;

        return $this;
    }

    public function setCompressionEnabled(bool $enabled): self
    {
        $this->config['websocket_compression'] = $enabled;

        return $this;
    }

    public function setWorkerNumber(int $num): self
    {
        $this->config['worker_num'] = $num;

        return $this;
    }

    public function getNative(): Server
    {
        return $this->server;
    }
}
