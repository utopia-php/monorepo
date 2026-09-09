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
    public const DEFAULT_SEND_TIMEOUT = 5.0;

    protected Server $server;

    protected string $host;

    protected int $port;

    public function __construct(string $host = '0.0.0.0', int $port = 80)
    {
        parent::__construct($host, $port);

        $this->server = new Server($this->host, $this->port);

        // Set maximum connections to Swoole's limit of 1 Million
        $this->config['max_connection'] = 1_000_000;
        $this->config['send_timeout'] = self::DEFAULT_SEND_TIMEOUT;
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
                if ($this->server->isEstablished($connection)) {
                    $pushed = $this->server->push(
                        $connection,
                        $message,
                        SWOOLE_WEBSOCKET_OPCODE_TEXT,
                        $flags,
                    );

                    if (!$pushed && $this->server->exist($connection)) {
                        // Discard queued output: a graceful close would keep
                        // waiting for the same client to drain its buffer.
                        $this->server->close($connection, true);
                    }
                } else {
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

    /**
     * Sets the timeout in seconds for each wait on a full output buffer.
     */
    public function setSendTimeout(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('Send timeout must be a finite positive number of seconds');
        }

        $this->config['send_timeout'] = $seconds;

        return $this;
    }

    public function getNative(): Server
    {
        return $this->server;
    }
}
