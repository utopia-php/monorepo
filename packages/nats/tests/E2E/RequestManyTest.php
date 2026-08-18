<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;

/**
 * Scatter-gather requestMany (ADR-47) against a live server with three
 * responders sharing one subject.
 */
final class RequestManyTest extends TestCase
{
    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    /**
     * Attach three responders to $subject on $conn, each replying with its tag.
     */
    private function attachResponders(Connection $conn, string $subject): void
    {
        foreach (['r1', 'r2', 'r3'] as $tag) {
            $conn->subscribe($subject, function ($msg) use ($conn, $tag): void {
                if ($msg->replyTo !== null) {
                    $conn->publish($msg->replyTo, $tag);
                }
            });
        }
    }

    public function testCollectsAllResponders(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $subject = 'test.rm.all.' . uniqid();
        $this->attachResponders($conn, $subject);

        $replies = $conn->requestMany($subject, 'ping', ['timeout' => 2.0]);

        $this->assertCount(3, $replies);
        $bodies = array_map(fn(\Utopia\NATS\Message $m): string => $m->data, $replies);
        sort($bodies);
        $this->assertSame(['r1', 'r2', 'r3'], $bodies);

        $conn->close();
    }

    public function testMaxStopsEarly(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $subject = 'test.rm.max.' . uniqid();
        $this->attachResponders($conn, $subject);

        $replies = $conn->requestMany($subject, 'ping', ['max' => 2, 'timeout' => 2.0]);

        $this->assertCount(2, $replies);

        $conn->close();
    }

    public function testTimeoutBoundsWhenMaxUnreached(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $subject = 'test.rm.timeout.' . uniqid();
        $this->attachResponders($conn, $subject);

        // Ask for more replies than exist: the overall timeout is the stop
        // condition, and we still collect the three that did answer.
        $start = microtime(true);
        $replies = $conn->requestMany($subject, 'ping', ['max' => 10, 'timeout' => 0.8]);
        $elapsed = microtime(true) - $start;

        $this->assertCount(3, $replies);
        $this->assertGreaterThanOrEqual(0.7, $elapsed);

        $conn->close();
    }

    public function testStallStopsBeforeTimeout(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $subject = 'test.rm.stall.' . uniqid();
        $this->attachResponders($conn, $subject);

        // Three fast replies then silence. A short stall window returns well
        // before the generous overall timeout would.
        $start = microtime(true);
        $replies = $conn->requestMany($subject, 'ping', ['timeout' => 3.0, 'stall' => 0.3]);
        $elapsed = microtime(true) - $start;

        $this->assertCount(3, $replies);
        $this->assertLessThan(1.5, $elapsed);

        $conn->close();
    }

    public function testEmptyWhenNoResponders(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $subject = 'test.rm.none.' . uniqid();

        // No subscribers: the server's 503 no-responders reply means zero
        // responders, so requestMany yields an empty list.
        $replies = $conn->requestMany($subject, 'ping', ['timeout' => 2.0]);

        $this->assertSame([], $replies);

        $conn->close();
    }
}
