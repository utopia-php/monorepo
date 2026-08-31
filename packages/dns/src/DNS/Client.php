<?php

declare(strict_types=1);

namespace Utopia\DNS;

use Exception;
use Utopia\Validator\IP;

class Client
{
    /**
     * The resolver configuration libresolv reads, and therefore what the host
     * itself resolves with.
     */
    public const string SYSTEM_RESOLV_CONF = '/etc/resolv.conf';

    /**
     * Build a client that queries the system's own resolver.
     *
     * Uses the first nameserver declared in resolv.conf. The `search` list is
     * deliberately not applied: a caller vetting a public hostname must not have
     * a bare name silently expanded into an internal one, and under a typical
     * Kubernetes `ndots:5` that expansion also costs several failed lookups
     * before the real one. Pass a name that is already fully qualified.
     *
     * @throws Exception when no nameserver can be read from $path
     */
    public static function fromSystem(
        string $path = self::SYSTEM_RESOLV_CONF,
        int $port = 53,
        int $timeout = 5,
        bool $useTcp = false,
    ): self {
        $nameservers = self::systemNameservers($path);

        if ($nameservers === []) {
            throw new Exception("No nameserver found in {$path}.");
        }

        return new self($nameservers[0], $port, $timeout, $useTcp);
    }

    /**
     * Every nameserver address declared in resolv.conf, in file order.
     *
     * Exposed so callers that want failover can try each in turn; `fromSystem()`
     * takes the first. Malformed addresses are skipped rather than fatal, so one
     * bad line cannot cost you a working resolver.
     *
     * @return list<string>
     *
     * @throws Exception when $path cannot be read
     */
    public static function systemNameservers(string $path = self::SYSTEM_RESOLV_CONF): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new Exception("Unable to read {$path}.");
        }

        $validator = new IP(IP::ALL);
        $nameservers = [];

        foreach (preg_split("/\R/", $contents) ?: [] as $line) {
            // `#` and `;` both open a comment, and either may trail a directive.
            $line = trim(preg_replace('/[#;].*$/', '', $line) ?? '');

            $fields = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($fields === false) {
                continue;
            }
            if (\count($fields) !== 2) {
                continue;
            }
            if ($fields[0] !== 'nameserver') {
                continue;
            }

            // A scoped address (fe80::1%eth0) names an interface the socket is
            // not bound to, so it is unusable here.
            if (!$validator->isValid($fields[1])) {
                continue;
            }

            $nameservers[] = $fields[1];
        }

        return array_values(array_unique($nameservers));
    }

    public function __construct(
        protected string $server = '127.0.0.1',
        protected int $port = 53,
        protected int $timeout = 5,
        protected bool $useTcp = false,
        /** @var \Socket|null */
        protected ?\Socket $socket = null,
    ) {
        $validator = new IP(IP::ALL); // IPv4 + IPv6
        if (!$validator->isValid($server)) {
            throw new Exception('Server must be an IP address.');
        }

        if ($this->useTcp) {
            return;
        }

        $domain = filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? AF_INET6
            : AF_INET;

        $socket = socket_create($domain, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            throw new Exception('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }

        // Set socket timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);

        $this->socket = $socket;
    }

    public function getServer(): string
    {
        return $this->server;
    }

    public function query(Message $message): Message
    {
        if ($this->useTcp) {
            return $this->queryTcp($message);
        }

        if (!$this->socket instanceof \Socket) {
            throw new Exception('UDP socket not initialized.');
        }

        $packet = $message->encode();
        if (socket_sendto($this->socket, $packet, \strlen($packet), 0, $this->server, $this->port) === false) {
            throw new Exception('Failed to send data: ' . socket_strerror(socket_last_error($this->socket)));
        }

        $data = '';
        $from = '';
        $port = 0;

        $result = socket_recvfrom($this->socket, $data, 512, 0, $from, $port);

        if ($result === false) {
            $error = socket_last_error($this->socket);
            $errorMessage = socket_strerror($error);
            throw new Exception("Failed to receive data from $this->server: $errorMessage (Error code: $error)");
        }

        if (empty($data) || !\is_string($data)) {
            throw new Exception("Empty response received from $this->server:$this->port");
        }

        return $this->decodeResponse($message, $data);
    }

    protected function queryTcp(Message $message): Message
    {
        $targetHost = $this->formatTcpHost($this->server);
        $uri = "tcp://{$targetHost}:{$this->port}";

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            $errCode = \is_int($errno) ? $errno : 0;
            $errMsg = \is_string($errstr) ? $errstr : 'Unknown error';
            throw new Exception("Failed to connect to {$this->server}:{$this->port} over TCP: $errMsg ($errCode)");
        }

        try {
            stream_set_timeout($socket, $this->timeout);

            $packet = $message->encode();
            $frame = pack('n', \strlen($packet)) . $packet;

            $written = fwrite($socket, $frame);

            if ($written === false || $written < \strlen($frame)) {
                throw new Exception('Failed to send full TCP DNS query.');
            }

            $lengthBytes = $this->readBytes($socket, 2);

            if (\strlen($lengthBytes) !== 2) {
                throw new Exception('Failed to read DNS TCP length prefix.');
            }

            $unpacked = unpack('nlen', $lengthBytes);
            $length = (\is_array($unpacked) && isset($unpacked['len']) && \is_int($unpacked['len'])) ? $unpacked['len'] : 0;

            if ($length === 0) {
                throw new Exception('Received empty DNS TCP response.');
            }

            $payload = $this->readBytes($socket, $length);

            if (\strlen($payload) !== $length) {
                throw new Exception('Incomplete DNS TCP response received.');
            }

            return $this->decodeResponse($message, $payload);
        } finally {
            fclose($socket);
        }
    }

    protected function decodeResponse(Message $query, string $payload): Message
    {
        $response = Message::decode($payload);

        if ($response->header->id !== $query->header->id) {
            throw new Exception("Mismatched DNS transaction ID. Expected {$query->header->id}, got {$response->header->id}");
        }

        return $response;
    }

    protected function readBytes(mixed $socket, int $length): string
    {
        if (!\is_resource($socket)) {
            return '';
        }

        $data = '';

        while (\strlen($data) < $length) {
            $remaining = $length - \strlen($data);

            if ($remaining <= 0) {
                break;
            }

            $chunk = fread($socket, max(1, $remaining));

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);

                if (!empty($meta['timed_out']) || !empty($meta['eof'])) {
                    break;
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    protected function formatTcpHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $host . ']';
        }

        return $host;
    }
}
