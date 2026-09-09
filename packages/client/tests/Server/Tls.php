<?php

declare(strict_types=1);

namespace Utopia\Tests\Server;

use RuntimeException;

final class Tls
{
    /**
     * A local CA signs a server certificate for $peerName. The callback receives
     * the listening port and CA file, without changing the machine's trust store.
     * Returns the HTTP requests actually received, after TLS completed.
     *
     * @param callable(int, string): void $test
     * @return list<array{connection: int, method: string, path: string, body: string}>
     */
    public static function serve(callable $test, string $peerName = 'localhost', ?string $ipAddress = null): array
    {
        $directory = sys_get_temp_dir() . '/utopia-client-tls-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create TLS fixture directory.');
        }

        $server = null;
        try {
            self::certificates($directory, $peerName, $ipAddress);
            $code = <<<'PHP'
                $directory = $argv[1];
                $context = stream_context_create(['ssl' => [
                    'local_cert' => $directory . '/server.pem',
                    'local_pk' => $directory . '/server.key',
                    'verify_peer' => false,
                ]]);
                $server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
                if (!is_resource($server)) {
                    exit(1);
                }
                $address = stream_socket_get_name($server, false);
                $port = (int) substr($address, strrpos($address, ':') + 1);
                file_put_contents($directory . '/port', (string) $port);
                $number = 0;
                while (true) {
                    $connection = @stream_socket_accept($server, 2);
                    if (!is_resource($connection)) {
                        continue;
                    }
                    $number++;
                    stream_set_timeout($connection, 2);
                    while (true) {
                        $line = fgets($connection);
                        if ($line === false) {
                            break;
                        }
                        $parts = explode(' ', trim($line));
                        $method = $parts[0];
                        $path = $parts[1];
                        $length = 0;
                        while (($line = fgets($connection)) !== false && trim($line) !== '') {
                            if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $match)) {
                                $length = (int) $match[1];
                            }
                        }
                        $body = '';
                        while (strlen($body) < $length) {
                            $chunk = fread($connection, $length - strlen($body));
                            if ($chunk === false || $chunk === '') {
                                break;
                            }
                            $body .= $chunk;
                        }
                        file_put_contents($directory . '/requests', json_encode([
                            'connection' => $number, 'method' => $method, 'path' => $path, 'body' => $body,
                        ]) . "\n", FILE_APPEND);
                        $response = $path === '/redirect'
                            ? "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1:" . $port . "/target\r\nContent-Length: 0\r\n\r\n"
                            : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
                        if (@fwrite($connection, $response) === false) {
                            break;
                        }
                    }
                    fclose($connection);
                }
                PHP;

            $server = proc_open([
                \PHP_BINARY, '-r', $code, $directory,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/stdout', 'w'],
                2 => ['file', $directory . '/stderr', 'w'],
            ], $pipes);
            if (!\is_resource($server)) {
                throw new RuntimeException('Unable to start TLS fixture.');
            }

            for ($attempt = 0; $attempt < 500 && !is_file($directory . '/port'); $attempt++) {
                if (!proc_get_status($server)['running']) {
                    throw new RuntimeException('TLS fixture exited before listening.');
                }

                usleep(10_000);
            }

            if (!is_file($directory . '/port')) {
                throw new RuntimeException('TLS fixture did not start.');
            }

            $test((int) file_get_contents($directory . '/port'), $directory . '/ca.pem');

            $lines = is_file($directory . '/requests') ? file($directory . '/requests', FILE_IGNORE_NEW_LINES) : [];
            if ($lines === false) {
                throw new RuntimeException('Unable to read TLS fixture requests.');
            }

            return array_map(static function (string $line): array {
                /** @var array{connection: int, method: string, path: string, body: string} $request */
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                return $request;
            }, $lines);
        } finally {
            if (\is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }

            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }

    private static function certificates(string $directory, string $peerName, ?string $ipAddress): void
    {
        $config = $directory . '/openssl.cnf';
        $subjectAltName = 'DNS:' . $peerName . ($ipAddress === null ? '' : ',IP:' . $ipAddress);
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=" . $subjectAltName . "\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
        $caKey = openssl_pkey_new($options);
        $serverKey = openssl_pkey_new($options);
        if ($caKey === false || $serverKey === false) {
            throw new RuntimeException('Unable to generate TLS fixture keys.');
        }

        $caCsr = openssl_csr_new(['commonName' => 'Utopia test CA'], $caKey, $options);
        $serverCsr = openssl_csr_new(['commonName' => $peerName], $serverKey, $options);
        if (!$caCsr instanceof \OpenSSLCertificateSigningRequest || !$serverCsr instanceof \OpenSSLCertificateSigningRequest
            || !$caKey instanceof \OpenSSLAsymmetricKey || !$serverKey instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to generate TLS fixture requests.');
        }

        $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $options + ['x509_extensions' => 'ca']);
        if ($ca === false || !openssl_x509_export_to_file($ca, $directory . '/ca.pem')) {
            throw new RuntimeException('Unable to sign TLS fixture CA.');
        }

        $certificate = openssl_csr_sign($serverCsr, $ca, $caKey, 1, $options + ['x509_extensions' => 'server']);
        if ($certificate === false || !openssl_x509_export_to_file($certificate, $directory . '/server.pem')
            || !openssl_pkey_export_to_file($serverKey, $directory . '/server.key', null, $options)) {
            throw new RuntimeException('Unable to sign TLS fixture certificate.');
        }
    }
}
