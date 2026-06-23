<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Integration;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests require a running NATS server at localhost:4222.
 */
final class RequestReplyTest extends TestCase
{
    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    public function testRequestReply(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        // Set up responder
        $sub = $conn->subscribe('test.echo', function ($msg) use ($conn) {
            if ($msg->replyTo !== null) {
                $conn->publish($msg->replyTo, 'echo: ' . $msg->data);
            }
        });

        $response = $conn->request('test.echo', 'hello', 2.0);
        $this->assertSame('echo: hello', $response->data);

        $sub->unsubscribe();
        $conn->close();
    }

    public function testRequestTimeout(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        $this->expectException(TimeoutException::class);
        $conn->request('test.no-responder-' . uniqid(), 'hello', 0.5);

        $conn->close();
    }
}
