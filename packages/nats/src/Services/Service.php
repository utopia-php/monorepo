<?php

declare(strict_types=1);

namespace Utopia\NATS\Services;

use Utopia\NATS\Connection;
use Utopia\NATS\Headers;
use Utopia\NATS\Message;
use Utopia\NATS\Subscription;

/**
 * A NATS micro service: request/reply endpoints plus the
 * $SRV.PING / $SRV.INFO / $SRV.STATS discovery protocol.
 */
final class Service
{
    private const API_PREFIX = '$SRV';
    private const TYPE_PREFIX = 'io.nats.micro.v1';

    private readonly string $id;
    private readonly string $started;

    /** @var array<string, Endpoint> */
    private array $endpoints = [];

    /** @var list<Subscription> */
    private array $subscriptions = [];

    private bool $running = false;

    public function __construct(
        private readonly Connection $conn,
        private readonly string $name,
        private readonly string $version,
        private readonly string $description = '',
    ) {
        $this->id = strtoupper(bin2hex(random_bytes(11)));
        $this->started = gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Register an endpoint handler. The handler receives the request
     * Message and returns the reply payload as a string.
     *
     * @param callable(Message): string $handler
     */
    public function addEndpoint(string $name, string $subject, callable $handler): self
    {
        $this->endpoints[$name] = new Endpoint($name, $subject, $handler);

        if ($this->running) {
            $this->subscribeEndpoint($this->endpoints[$name]);
        }

        return $this;
    }

    /**
     * Subscribe all endpoint handlers and discovery responders.
     * Non-blocking: the caller must pump the connection.
     */
    public function start(): self
    {
        if ($this->running) {
            return $this;
        }
        $this->running = true;

        foreach ($this->endpoints as $endpoint) {
            $this->subscribeEndpoint($endpoint);
        }

        $this->subscribeDiscovery('PING', fn(Message $msg) => $this->reply($msg, $this->pingResponse()));
        $this->subscribeDiscovery('INFO', fn(Message $msg) => $this->reply($msg, $this->infoResponse()));
        $this->subscribeDiscovery('STATS', fn(Message $msg) => $this->reply($msg, $this->statsResponse()));

        return $this;
    }

    /**
     * Start and then block, processing messages until stopped.
     */
    public function run(): void
    {
        $this->start();
        $this->conn->wait();
    }

    public function stop(): void
    {
        foreach ($this->subscriptions as $sub) {
            try {
                $sub->unsubscribe();
            } catch (\Throwable) {
                // ignore
            }
        }
        $this->subscriptions = [];
        $this->running = false;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function subscribeEndpoint(Endpoint $endpoint): void
    {
        $this->subscriptions[] = $this->conn->subscribe(
            $endpoint->subject,
            function (Message $msg) use ($endpoint): void {
                $this->handleEndpoint($endpoint, $msg);
            },
            $endpoint->queueGroup,
        );
    }

    private function handleEndpoint(Endpoint $endpoint, Message $msg): void
    {
        $endpoint->numRequests++;
        $start = hrtime(true);

        try {
            $result = ($endpoint->handler)($msg);
            $endpoint->processingTime += (int) (hrtime(true) - $start);

            if ($msg->replyTo !== null) {
                $this->conn->publish($msg->replyTo, $result);
            }
        } catch (\Throwable $e) {
            $endpoint->processingTime += (int) (hrtime(true) - $start);
            $endpoint->numErrors++;
            $endpoint->lastError = $e->getMessage();

            if ($msg->replyTo !== null) {
                $headers = new Headers();
                $headers->set('Nats-Service-Error', $e->getMessage());
                $headers->set('Nats-Service-Error-Code', '500');
                $this->conn->publish($msg->replyTo, '', headers: $headers);
            }
        }
    }

    private function subscribeDiscovery(string $verb, \Closure $callback): void
    {
        foreach ([
            self::API_PREFIX . ".{$verb}",
            self::API_PREFIX . ".{$verb}.{$this->name}",
            self::API_PREFIX . ".{$verb}.{$this->name}.{$this->id}",
        ] as $subject) {
            $this->subscriptions[] = $this->conn->subscribe($subject, $callback);
        }
    }

    private function reply(Message $msg, string $payload): void
    {
        if ($msg->replyTo !== null) {
            $this->conn->publish($msg->replyTo, $payload);
        }
    }

    private function pingResponse(): string
    {
        return $this->encode([
            'type' => self::TYPE_PREFIX . '.ping_response',
            'name' => $this->name,
            'id' => $this->id,
            'version' => $this->version,
            'metadata' => new \stdClass(),
        ]);
    }

    private function infoResponse(): string
    {
        return $this->encode([
            'type' => self::TYPE_PREFIX . '.info_response',
            'name' => $this->name,
            'id' => $this->id,
            'version' => $this->version,
            'description' => $this->description,
            'metadata' => new \stdClass(),
            'endpoints' => array_map(fn(Endpoint $e): array => $e->info(), array_values($this->endpoints)),
        ]);
    }

    private function statsResponse(): string
    {
        return $this->encode([
            'type' => self::TYPE_PREFIX . '.stats_response',
            'name' => $this->name,
            'id' => $this->id,
            'version' => $this->version,
            'started' => $this->started,
            'metadata' => new \stdClass(),
            'endpoints' => array_map(fn(Endpoint $e): array => $e->stats(), array_values($this->endpoints)),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
