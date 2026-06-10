<?php

declare(strict_types=1);

namespace Utopia\Tests\Server;

use RuntimeException;

final class Http
{
    /**
     * Run the routed test server (server.php) for the duration of the callable.
     * The server is started, awaited until ready, and torn down automatically.
     *
     * @param callable(int): void $test receives the listening port
     */
    public static function serve(callable $test): void
    {
        $port = self::availablePort();
        $server = self::start($port);

        try {
            $test($port);
        } finally {
            self::stop($server);
        }
    }

    /**
     * Run a server that replies with $response verbatim to a single request,
     * for the duration of the callable. Started, awaited, and torn down for you.
     *
     * @param callable(int): void $test receives the listening port
     */
    public static function raw(string $response, callable $test): void
    {
        $port = self::availablePort();
        $server = self::startRaw($port, $response);

        try {
            $test($port);
        } finally {
            self::stop($server);
        }
    }

    /**
     * Run the callable against a port with nothing listening on it, so a
     * connection attempt is refused. No server is started or torn down.
     *
     * @param callable(int): void $test receives the unbound port
     */
    public static function unbound(callable $test): void
    {
        $test(self::availablePort());
    }

    private static function availablePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to find an available TCP port: ' . $errorCode . ' ' . $errorMessage);
        }

        $name = stream_socket_get_name($server, false);

        fclose($server);

        if ($name === false) {
            throw new RuntimeException('Unable to read TCP port.');
        }

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);

        if (!\is_int($port)) {
            throw new RuntimeException('Unable to parse TCP port.');
        }

        return $port;
    }

    /**
     * @return resource
     */
    private static function start(int $port): mixed
    {
        $server = proc_open(
            [\PHP_BINARY, '-d', 'post_max_size=64M', '-d', 'upload_max_filesize=64M', '-d', 'memory_limit=256M', '-S', '127.0.0.1:' . $port, \dirname(__DIR__) . '/server.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start PHP test server.');
        }

        unset($pipes);
        self::waitForPort($port);

        return $server;
    }

    /**
     * @return resource
     */
    private static function startRaw(int $port, string $response): mixed
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'utopia-raw-server-');

        if ($readyFile === false) {
            throw new RuntimeException('Unable to create raw response server readiness file.');
        }

        unlink($readyFile);

        $code = <<<'PHP'
$port = (int) $argv[1];
$response = base64_decode($argv[2]);
$readyFile = $argv[3];
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, $errorCode . ' ' . $errorMessage);
    exit(1);
}
file_put_contents($readyFile, 'ready');
$connection = @stream_socket_accept($server, 10);
if (is_resource($connection)) {
    fread($connection, 8192);
    fwrite($connection, $response);
    fclose($connection);
}
fclose($server);
PHP;

        $server = proc_open(
            [\PHP_BINARY, '-r', $code, (string) $port, base64_encode($response), $readyFile],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start raw response test server.');
        }

        unset($pipes);
        self::waitForReadyFile($readyFile);

        return $server;
    }

    /**
     * @param resource $server
     */
    private static function stop(mixed $server): void
    {
        proc_terminate($server);
        proc_close($server);
    }

    private static function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5;

        do {
            $connection = @fsockopen('127.0.0.1', $port);

            if (\is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('PHP test server did not start.');
    }

    private static function waitForReadyFile(string $readyFile): void
    {
        $deadline = microtime(true) + 5;

        do {
            if (is_file($readyFile)) {
                unlink($readyFile);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Raw response test server did not start.');
    }
}
