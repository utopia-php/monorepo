<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Headers;

final class Consumer
{
    public function __construct(
        private readonly Connection $conn,
        private readonly string $stream,
        private ConsumerInfo $info,
        private readonly string $apiPrefix = '$JS.API',
    ) {
    }

    /**
     * Fetch a batch of messages from the consumer.
     */
    public function fetch(int $batch = 1, ?float $timeout = null): MessageBatch
    {
        $timeout ??= 5.0;
        $requestSubject = "{$this->apiPrefix}.CONSUMER.MSG.NEXT.{$this->stream}.{$this->getName()}";

        $payload = json_encode([
            'batch' => $batch,
            'expires' => StreamConfig::secondsToNanos($timeout),
        ], JSON_THROW_ON_ERROR);

        $inbox = $this->conn->newInbox();
        $sub = $this->conn->subscribe($inbox);

        $this->conn->publish($requestSubject, $payload, $inbox);

        $messageBatch = new MessageBatch($this->conn);
        $deadline = microtime(true) + $timeout;

        while (count($messageBatch) < $batch) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $msg = $sub->nextMessage($remaining);
            if ($msg === null) {
                break;
            }

            // Check for status messages (408 = Request Timeout, 404 = No Messages, 409 = Leadership Change)
            if ($msg->headers !== null) {
                $status = $msg->headers->getStatus();
                if ($status === '408' || $status === '404' || $status === '409') {
                    break;
                }
            }

            $messageBatch->addMessage($msg);
        }

        $sub->unsubscribe();

        return $messageBatch;
    }

    /**
     * Fetch the next single message.
     */
    public function next(?float $timeout = null): ?JetStreamMessage
    {
        $batch = $this->fetch(1, $timeout);
        $messages = $batch->getMessages();
        return $messages[0] ?? null;
    }

    public function info(bool $refresh = false): ConsumerInfo
    {
        if ($refresh) {
            $subject = "{$this->apiPrefix}.CONSUMER.INFO.{$this->stream}.{$this->getName()}";
            try {
                $response = $this->conn->request($subject);
                $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
                JetStream::checkError($data);
                $this->info = ConsumerInfo::fromArray($data);
            } catch (TimeoutException) {
                // Return cached info on timeout
            }
        }
        return $this->info;
    }

    public function getName(): string
    {
        return $this->info->name;
    }

    public function getStream(): string
    {
        return $this->stream;
    }
}
