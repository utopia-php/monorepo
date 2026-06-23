<?php

declare(strict_types=1);

namespace Utopia\NATS;

use Utopia\NATS\Auth\Authenticator;
use Utopia\NATS\Auth\CredentialsAuth;
use Utopia\NATS\Auth\NKeyAuth;
use Utopia\NATS\Auth\NoAuth;
use Utopia\NATS\Auth\TokenAuth;
use Utopia\NATS\Auth\UserPassAuth;
use Utopia\NATS\Exception\AuthenticationException;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\MaxPayloadException;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\PermissionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Protocol\Parser;
use Utopia\NATS\Protocol\ServerOp;
use Utopia\NATS\Protocol\Writer;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;
use Utopia\NATS\Transport\Transport;

final class Connection
{
    private const STATUS_DISCONNECTED = 'disconnected';
    private const STATUS_CONNECTING = 'connecting';
    private const STATUS_CONNECTED = 'connected';
    private const STATUS_RECONNECTING = 'reconnecting';
    private const STATUS_DRAINING = 'draining';
    private const STATUS_CLOSED = 'closed';

    private const CLIENT_LANG = 'php';
    private const CLIENT_VERSION = '0.1.0';

    private Transport $transport;
    private Parser $parser;
    private Writer $writer;
    private Authenticator $auth;
    private ServerInfo $serverInfo;
    private ConnectionOptions $options;

    /** @var array<string, Subscription> */
    private array $subscriptions = [];
    private int $nextSid = 1;
    private string $status = self::STATUS_DISCONNECTED;
    private int $outstandingPings = 0;
    private float $lastPingTime = 0.0;

    // Mux inbox for request-reply
    private ?Subscription $inboxSub = null;
    private string $inboxPrefix = '';
    /** @var array<string, array{message: ?Message, resolved: bool}> */
    private array $pendingRequests = [];

    // Reconnection
    /** @var list<string> */
    private array $serverPool = [];
    private int $currentServerIndex = 0;
    private int $reconnectAttempts = 0;
    /** @var list<string> */
    private array $pendingBuffer = [];

    private function __construct()
    {
        $this->writer = new Writer();
    }

    /**
     * Connect to a NATS server.
     *
     * @param string|list<string>|ConnectionOptions $urlOrOptions
     */
    public static function connect(
        string|array|ConnectionOptions $urlOrOptions = 'nats://127.0.0.1:4222',
        ?ConnectionOptions $options = null,
    ): self {
        if ($urlOrOptions instanceof ConnectionOptions) {
            $options = $urlOrOptions;
        } elseif ($options === null) {
            $options = new ConnectionOptions(servers: $urlOrOptions);
        }

        $conn = new self();
        $conn->options = $options;
        $conn->auth = $conn->resolveAuthenticator($options);
        $conn->serverPool = $conn->buildServerPool($options);
        $conn->doConnect();

        return $conn;
    }

    public function publish(string $subject, string $data = '', ?string $replyTo = null, ?Headers $headers = null): void
    {
        $this->ensureConnected();

        if (isset($this->serverInfo) && strlen($data) > $this->serverInfo->maxPayload) {
            throw new MaxPayloadException(
                "Payload size " . strlen($data) . " exceeds server maximum of {$this->serverInfo->maxPayload}"
            );
        }

        if ($headers !== null && !empty($headers->all())) {
            $headerWire = $headers->toWire();
            $cmd = $this->writer->hpub($subject, $headerWire, $data, $replyTo);
        } else {
            $cmd = $this->writer->pub($subject, $data, $replyTo);
        }

        $this->send($cmd);
    }

    public function subscribe(string $subject, ?\Closure $callback = null, ?string $queue = null): Subscription
    {
        $this->ensureConnected();

        $sid = (string) $this->nextSid++;
        $sub = new Subscription($sid, $subject, $queue, $callback);
        $sub->setConnection($this);

        $this->subscriptions[$sid] = $sub;
        $this->send($this->writer->sub($subject, $sid, $queue));

        return $sub;
    }

    public function queueSubscribe(string $subject, string $queue, ?\Closure $callback = null): Subscription
    {
        return $this->subscribe($subject, $callback, $queue);
    }

    public function unsubscribe(Subscription $sub, ?int $maxMessages = null): void
    {
        if ($maxMessages !== null) {
            $sub->setMaxMessages($sub->getReceived() + $maxMessages);
            $this->send($this->writer->unsub($sub->sid, $maxMessages));
        } else {
            $sub->setInactive();
            unset($this->subscriptions[$sub->sid]);
            $this->send($this->writer->unsub($sub->sid));
        }
    }

    public function request(string $subject, string $data = '', ?float $timeout = null, ?Headers $headers = null): Message
    {
        $this->ensureConnected();

        $timeout ??= $this->options->requestTimeout;
        $this->ensureInboxSub();

        $token = Inbox::generateId();
        $replyTo = $this->inboxPrefix . '.' . $token;
        $this->pendingRequests[$token] = ['message' => null, 'resolved' => false];

        $this->publish($subject, $data, $replyTo, $headers);

        $deadline = microtime(true) + $timeout;

        while (!$this->pendingRequests[$token]['resolved']) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                unset($this->pendingRequests[$token]);
                throw new TimeoutException("Request timed out after {$timeout}s");
            }

            $this->processMessage($remaining);
        }

        $msg = $this->pendingRequests[$token]['message'];
        unset($this->pendingRequests[$token]);

        if ($msg === null) {
            throw new TimeoutException("Request timed out after {$timeout}s");
        }

        // Check for no responders (status 503)
        if ($msg->headers !== null && $msg->headers->getStatus() === '503') {
            throw new NatsException('No responders for request');
        }

        return $msg;
    }

    public function newInbox(): string
    {
        return Inbox::create($this->options->inboxPrefix);
    }

    /**
     * Read and dispatch one server message.
     */
    public function processMessage(?float $timeout = null): ?Message
    {
        $this->checkPings();

        try {
            [$op, $data] = $this->parser->next($timeout);
        } catch (TimeoutException) {
            return null;
        } catch (ConnectionException $e) {
            if ($this->options->allowReconnect && $this->status !== self::STATUS_CLOSED) {
                $this->attemptReconnect();
                return null;
            }
            throw $e;
        }

        return $this->dispatchOp($op, $data);
    }

    /**
     * Process messages in a loop.
     *
     * @param int $count Number of messages to process (0 = forever)
     */
    public function wait(int $count = 0, ?float $timeout = null): void
    {
        $processed = 0;
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while ($count === 0 || $processed < $count) {
            $remaining = $deadline !== null ? $deadline - microtime(true) : null;
            if ($remaining !== null && $remaining <= 0) {
                return;
            }

            $msg = $this->processMessage($remaining);
            if ($msg !== null) {
                $processed++;
            }
        }
    }

    public function jetStream(?string $domain = null, ?string $apiPrefix = null): JetStream\JetStream
    {
        return new JetStream\JetStream($this, $domain, $apiPrefix);
    }

    public function flush(?float $timeout = null): void
    {
        $this->ensureConnected();
        $timeout ??= $this->options->connectTimeout;

        $this->send($this->writer->ping());
        $deadline = microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException('Flush timed out');
            }

            [$op, $data] = $this->parser->next($remaining);
            if ($op === ServerOp::Pong) {
                $this->outstandingPings = 0;
                return;
            }
            $this->dispatchOp($op, $data);
        }
    }

    public function drain(?float $timeout = null): void
    {
        if ($this->status !== self::STATUS_CONNECTED) {
            return;
        }

        $this->status = self::STATUS_DRAINING;
        $timeout ??= $this->options->drainTimeout;

        // Unsub all subscriptions
        foreach ($this->subscriptions as $sub) {
            $this->send($this->writer->unsub($sub->sid));
        }

        // Process remaining messages
        $deadline = microtime(true) + $timeout;
        while (!empty($this->subscriptions)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            try {
                $this->processMessage($remaining);
            } catch (TimeoutException) {
                break;
            }
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $previousStatus = $this->status;
        $this->status = self::STATUS_CLOSED;

        foreach ($this->subscriptions as $sub) {
            $sub->setInactive();
        }
        $this->subscriptions = [];
        $this->pendingRequests = [];

        if (isset($this->transport)) {
            $this->transport->close();
        }

        if ($previousStatus === self::STATUS_CONNECTED && $this->options->onClose !== null) {
            ($this->options->onClose)();
        }
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isReconnecting(): bool
    {
        return $this->status === self::STATUS_RECONNECTING;
    }

    public function getServerInfo(): ServerInfo
    {
        return $this->serverInfo;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    // --- Internal ---

    private function doConnect(): void
    {
        $this->status = self::STATUS_CONNECTING;
        $lastError = null;

        foreach ($this->serverPool as $index => $url) {
            try {
                $this->connectToServer($url);
                $this->currentServerIndex = $index;
                $this->status = self::STATUS_CONNECTED;
                $this->reconnectAttempts = 0;
                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        $this->status = self::STATUS_DISCONNECTED;
        throw new ConnectionException(
            'Failed to connect to any NATS server',
            previous: $lastError,
        );
    }

    private function connectToServer(string $url): void
    {
        $parsed = $this->parseUrl($url);
        $host = $parsed['host'];
        $port = $parsed['port'];
        $scheme = $parsed['scheme'];

        // Create transport
        if ($scheme === 'tls' || $this->options->tls) {
            $this->transport = new TlsTransport([
                'cafile' => $this->options->tlsCaFile,
                'local_cert' => $this->options->tlsCertFile,
                'local_pk' => $this->options->tlsKeyFile,
            ]);
        } else {
            $this->transport = new TcpTransport();
        }

        $this->transport->connect($host, $port, $this->options->connectTimeout);
        $this->parser = new Parser($this->transport);

        // Read INFO
        [$op, $data] = $this->parser->next($this->options->connectTimeout);
        if ($op !== ServerOp::Info) {
            throw new ProtocolException("Expected INFO, got {$op->value}");
        }
        $this->serverInfo = ServerInfo::fromArray($data);

        // Merge connect_urls into server pool
        foreach ($this->serverInfo->connectUrls as $connectUrl) {
            if (!in_array($connectUrl, $this->serverPool, true)) {
                $this->serverPool[] = $this->normalizeUrl($connectUrl);
            }
        }

        // TLS upgrade if required by server
        if ($this->serverInfo->tlsRequired && $scheme !== 'tls' && !$this->options->tls) {
            $this->transport->upgradeTls([
                'cafile' => $this->options->tlsCaFile,
                'local_cert' => $this->options->tlsCertFile,
                'local_pk' => $this->options->tlsKeyFile,
            ]);
        }

        // Send CONNECT
        $connectPayload = $this->buildConnectPayload();
        $this->transport->write($this->writer->connect($connectPayload));

        // Send PING and wait for PONG to confirm connection
        $this->transport->write($this->writer->ping());

        // Read until we get PONG (skip +OK if verbose)
        $deadline = microtime(true) + $this->options->connectTimeout;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException('Connection handshake timed out');
            }

            [$op, $data] = $this->parser->next($remaining);

            if ($op === ServerOp::Pong) {
                break;
            }
            if ($op === ServerOp::Err) {
                $this->transport->close();
                $errMsg = is_string($data) ? $data : 'Unknown error';
                if (stripos($errMsg, 'authorization') !== false || stripos($errMsg, 'authentication') !== false) {
                    throw new AuthenticationException("Server error: {$errMsg}");
                }
                throw new ConnectionException("Server error: {$errMsg}");
            }
            // Skip +OK
        }

        $this->lastPingTime = microtime(true);
    }

    private function buildConnectPayload(): array
    {
        $payload = [
            'verbose' => $this->options->verbose,
            'pedantic' => $this->options->pedantic,
            'lang' => self::CLIENT_LANG,
            'version' => self::CLIENT_VERSION,
            'protocol' => 1,
            'echo' => $this->options->echo,
            'headers' => true,
            'no_responders' => true,
        ];

        if ($this->options->name !== '') {
            $payload['name'] = $this->options->name;
        }

        $authFields = $this->auth->authenticate($this->serverInfo->nonce);
        $payload = array_merge($payload, $authFields);

        return $payload;
    }

    private function dispatchOp(ServerOp $op, mixed $data): ?Message
    {
        return match ($op) {
            ServerOp::Msg, ServerOp::HMsg => $this->handleMessage($data),
            ServerOp::Ping => $this->handlePing(),
            ServerOp::Pong => $this->handlePong(),
            ServerOp::Err => $this->handleError($data),
            ServerOp::Ok => null,
            ServerOp::Info => $this->handleInfo($data),
        };
    }

    private function handleMessage(array $data): ?Message
    {
        $headers = null;
        if (isset($data['headers']) && $data['headers'] !== null) {
            $headers = Headers::fromWire($data['headers']);
        }

        $msg = new Message(
            subject: $data['subject'],
            data: $data['payload'],
            replyTo: $data['replyTo'],
            headers: $headers,
            sid: $data['sid'],
        );

        // Check if this is a reply to a pending request (mux inbox)
        if ($this->inboxSub !== null && $data['sid'] === $this->inboxSub->sid) {
            $token = $this->extractInboxToken($data['subject']);
            if ($token !== null && isset($this->pendingRequests[$token])) {
                $this->pendingRequests[$token] = ['message' => $msg, 'resolved' => true];
                return $msg;
            }
        }

        // Dispatch to subscription
        $sub = $this->subscriptions[$data['sid']] ?? null;
        if ($sub !== null && $sub->isActive()) {
            $sub->deliver($msg);

            // Clean up auto-unsubscribed subscriptions
            if (!$sub->isActive()) {
                unset($this->subscriptions[$data['sid']]);
            }
        }

        return $msg;
    }

    private function handlePing(): null
    {
        $this->send($this->writer->pong());
        return null;
    }

    private function handlePong(): null
    {
        $this->outstandingPings = max(0, $this->outstandingPings - 1);
        return null;
    }

    private function handleError(mixed $data): never
    {
        $message = is_string($data) ? $data : 'Unknown server error';

        if ($this->options->onError !== null) {
            ($this->options->onError)(new NatsException($message));
        }

        if (stripos($message, 'permissions violation') !== false) {
            throw new PermissionException($message);
        }
        if (stripos($message, 'authorization') !== false || stripos($message, 'authentication') !== false) {
            throw new AuthenticationException($message);
        }
        if (stripos($message, 'maximum payload') !== false) {
            throw new MaxPayloadException($message);
        }

        throw new ProtocolException("Server error: {$message}");
    }

    private function handleInfo(mixed $data): null
    {
        if (is_array($data)) {
            $this->serverInfo = ServerInfo::fromArray($data);
        }
        return null;
    }

    private function checkPings(): void
    {
        if ($this->status !== self::STATUS_CONNECTED) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastPingTime) >= $this->options->pingInterval) {
            if ($this->outstandingPings >= $this->options->maxPingsOut) {
                // Stale connection
                if ($this->options->allowReconnect) {
                    $this->attemptReconnect();
                    return;
                }
                throw new ConnectionException('Stale connection: too many outstanding pings');
            }

            try {
                $this->send($this->writer->ping());
                $this->outstandingPings++;
                $this->lastPingTime = $now;
            } catch (ConnectionException) {
                if ($this->options->allowReconnect) {
                    $this->attemptReconnect();
                }
            }
        }
    }

    private function attemptReconnect(): void
    {
        if ($this->status === self::STATUS_CLOSED || $this->status === self::STATUS_RECONNECTING) {
            return;
        }

        $this->status = self::STATUS_RECONNECTING;

        if ($this->options->onDisconnect !== null) {
            ($this->options->onDisconnect)();
        }

        if (isset($this->transport)) {
            $this->transport->close();
        }

        for ($attempt = 0; $attempt < $this->options->maxReconnectAttempts; $attempt++) {
            // Wait with jitter before reconnecting
            if ($attempt > 0) {
                $wait = $this->options->reconnectWait + (lcg_value() * $this->options->reconnectJitter);
                usleep((int) ($wait * 1_000_000));
            }

            foreach ($this->serverPool as $index => $url) {
                try {
                    $this->connectToServer($url);
                    $this->currentServerIndex = $index;
                    $this->status = self::STATUS_CONNECTED;
                    $this->reconnectAttempts = 0;
                    $this->outstandingPings = 0;

                    // Re-subscribe all active subscriptions
                    foreach ($this->subscriptions as $sub) {
                        if ($sub->isActive()) {
                            $this->send($this->writer->sub($sub->subject, $sub->sid, $sub->queue));
                        }
                    }

                    // Flush any buffered publishes
                    foreach ($this->pendingBuffer as $cmd) {
                        $this->send($cmd);
                    }
                    $this->pendingBuffer = [];

                    if ($this->options->onReconnect !== null) {
                        ($this->options->onReconnect)();
                    }

                    return;
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $this->status = self::STATUS_DISCONNECTED;
        throw new ConnectionException('Failed to reconnect to any NATS server');
    }

    private function ensureConnected(): void
    {
        if ($this->status !== self::STATUS_CONNECTED && $this->status !== self::STATUS_DRAINING) {
            throw new ConnectionException("Not connected (status: {$this->status})");
        }
    }

    private function send(string $data): void
    {
        if ($this->status === self::STATUS_RECONNECTING) {
            $this->pendingBuffer[] = $data;
            return;
        }

        try {
            $this->transport->write($data);
        } catch (ConnectionException $e) {
            if ($this->options->allowReconnect && $this->status !== self::STATUS_CLOSED) {
                $this->pendingBuffer[] = $data;
                $this->attemptReconnect();
                return;
            }
            throw $e;
        }
    }

    private function ensureInboxSub(): void
    {
        if ($this->inboxSub !== null) {
            return;
        }

        $this->inboxPrefix = $this->options->inboxPrefix . '.' . Inbox::generateId();
        $this->inboxSub = $this->subscribe($this->inboxPrefix . '.*');
    }

    private function extractInboxToken(string $subject): ?string
    {
        if (!str_starts_with($subject, $this->inboxPrefix . '.')) {
            return null;
        }

        return substr($subject, strlen($this->inboxPrefix) + 1);
    }

    private function resolveAuthenticator(ConnectionOptions $options): Authenticator
    {
        if ($options->credentialsFile !== null) {
            return new CredentialsAuth($options->credentialsFile);
        }

        if ($options->nkey !== null && $options->nkeySeed !== null) {
            return new NKeyAuth($options->nkey, $options->nkeySeed);
        }

        if ($options->token !== null) {
            return new TokenAuth($options->token);
        }

        if ($options->user !== null && $options->pass !== null) {
            return new UserPassAuth($options->user, $options->pass);
        }

        // Check URL for user info
        foreach ($options->servers as $url) {
            $parsed = parse_url($url);
            if (isset($parsed['user'])) {
                $user = rawurldecode($parsed['user']);
                $pass = isset($parsed['pass']) ? rawurldecode($parsed['pass']) : '';

                if ($pass !== '') {
                    return new UserPassAuth($user, $pass);
                }
                return new TokenAuth($user);
            }
        }

        return new NoAuth();
    }

    /**
     * @return list<string>
     */
    private function buildServerPool(ConnectionOptions $options): array
    {
        $servers = array_map(fn(string $url) => $this->normalizeUrl($url), $options->servers);

        if (!$options->noRandomize && count($servers) > 1) {
            shuffle($servers);
        }

        return $servers;
    }

    private function normalizeUrl(string $url): string
    {
        if (!preg_match('#^(nats|tls|ws|wss)://#', $url)) {
            $url = 'nats://' . $url;
        }
        return $url;
    }

    /**
     * @return array{scheme: string, host: string, port: int}
     */
    private function parseUrl(string $url): array
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new ConnectionException("Invalid server URL: {$url}");
        }

        return [
            'scheme' => $parsed['scheme'] ?? 'nats',
            'host' => $parsed['host'] ?? '127.0.0.1',
            'port' => $parsed['port'] ?? 4222,
        ];
    }
}
