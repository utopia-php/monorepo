<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\PermissionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;
use Utopia\NATS\Transport\WebSocketTransport;

/**
 * A connection the server has already closed must not keep reporting itself as
 * connected: the next publish would write into a dead socket and return success,
 * losing the message while reporting a send. Covers the -ERR classification, the
 * caller-independent keepalive, and the transports' closed-resource guards.
 */
final class ConnectionDeathDetectionTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function connect(FakeTransport $fake, array $extra = []): Connection
    {
        return Connection::connect(new ConnectionOptions(...array_merge([
            'servers' => 'nats://127.0.0.1:4222',
            'transportFactory' => fn(string $scheme): FakeTransport => $fake,
            // Keep reconnects instant and free of jitter so the assertions below
            // are about behaviour rather than timing.
            'reconnectWait' => 0.0,
            'reconnectJitter' => 0.0,
        ], $extra)));
    }

    // --- -ERR classification (pure) ---

    /**
     * @return list<array{string, bool}>
     */
    public static function serverErrorProvider(): array
    {
        return [
            ['Stale Connection', true],
            ['stale connection', true],
            ['Slow Consumer', true],
            ['Maximum Payload Exceeded', true],
            ['Invalid Client Protocol', true],
            // Not connection-closing, or deliberately excluded.
            ["Permissions Violation for Publish to 'foo'", false],
            ['Authorization Violation', false],
            ['Unknown Protocol Operation', false],
            ['', false],
        ];
    }

    #[DataProvider('serverErrorProvider')]
    public function testClosesConnectionClassifiesServerErrors(string $message, bool $expected): void
    {
        $this->assertSame($expected, Connection::closesConnection($message));
    }

    // --- Finding: reconnect on a connection-closing -ERR ---

    public function testConnectionClosingErrorTriggersReconnect(): void
    {
        $reconnected = false;
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'onReconnect' => function () use (&$reconnected): void {
                $reconnected = true;
            },
        ]);

        $fake->pushInbound("-ERR 'Stale Connection'\r\n");

        // The error still surfaces to the caller, but the connection underneath
        // has been rebuilt rather than left dead-but-"connected".
        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (ProtocolException) {
            // expected
        }

        $this->assertTrue($reconnected, 'A connection-closing -ERR must reconnect');
        $this->assertTrue($conn->isConnected());

        $conn->close();
    }

    public function testConnectionClosingErrorWithoutReconnectMarksConnectionDead(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, ['allowReconnect' => false]);

        $fake->pushInbound("-ERR 'Stale Connection'\r\n");

        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (ProtocolException) {
            // expected
        }

        // The whole point: status no longer claims a usable connection, so the
        // next publish raises instead of writing into a dead socket.
        $this->assertFalse($conn->isConnected());
        $this->expectException(ConnectionException::class);
        $conn->publish('foo', 'bar');
    }

    public function testNonClosingErrorLeavesConnectionIntact(): void
    {
        $reconnected = false;
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'onReconnect' => function () use (&$reconnected): void {
                $reconnected = true;
            },
        ]);

        $fake->pushInbound("-ERR 'Permissions Violation for Publish to \"foo\"'\r\n");

        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (PermissionException) {
            // expected
        }

        $this->assertFalse($reconnected, 'A permissions error must not recycle the connection');
        $this->assertTrue($conn->isConnected());

        $conn->close();
    }

    // --- Finding: keepalive independent of the caller ---

    public function testTickSendsKeepalivePingWhenDue(): void
    {
        $fake = new FakeTransport();
        // pingInterval 0 makes the keepalive due on every check.
        $conn = $this->connect($fake, ['pingInterval' => 0.0]);

        $before = $fake->written;
        $conn->tick();

        $this->assertStringContainsString(
            "PING\r\n",
            substr($fake->written, \strlen($before)),
            'tick() must drive the keepalive without a message being read',
        );

        $conn->close();
    }

    public function testTickRecyclesAConnectionThatStoppedAnsweringPings(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'pingInterval' => 0.0,
            'maxPingsOut' => 1,
        ]);

        // Counted off the wire rather than from a callback: a recycled
        // connection re-runs the handshake, so a fresh CONNECT is the effect
        // worth asserting on.
        $handshakes = fn(): int => substr_count($fake->written, 'CONNECT ');
        $this->assertSame(1, $handshakes());

        // First tick sends the PING; the PONG is never read, so the second tick
        // sees the outstanding ping budget exhausted and recycles.
        $conn->tick();
        $this->assertSame(1, $handshakes(), 'One unanswered ping is still within budget');

        $conn->tick();
        $this->assertGreaterThan(
            1,
            $handshakes(),
            'An unanswered keepalive must recycle the connection',
        );

        $conn->close();
    }

    public function testPublishDrivesTheKeepaliveBeforeWriting(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, ['pingInterval' => 0.0]);

        $offset = \strlen($fake->written);
        $conn->publish('foo', 'bar');
        $tail = substr($fake->written, $offset);

        $ping = strpos($tail, "PING\r\n");
        $pub = strpos($tail, 'PUB foo');

        $this->assertNotFalse($ping, 'publish() must check the keepalive');
        $this->assertNotFalse($pub);
        $this->assertLessThan(
            $pub,
            $ping,
            'The keepalive must run before the payload is written, not after',
        );

        $conn->close();
    }

    // --- Finding: transports must not raise TypeError on a closed stream ---

    /**
     * @return list<array{class-string}>
     */
    public static function streamTransportProvider(): array
    {
        return [
            [TcpTransport::class],
            [TlsTransport::class],
            [WebSocketTransport::class],
        ];
    }

    /**
     * A close() concurrent with a yielded fread/fwrite leaves a closed resource
     * behind. Every stream function raises TypeError on one, and TypeError is an
     * \Error -- so it bypasses the ConnectionException catches that drive
     * reconnection. The transports must report it as a connection failure.
     *
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testClosedStreamRaisesConnectionExceptionNotTypeError(string $class): void
    {
        $transport = $this->transportWithClosedStream($class);

        $this->expectException(ConnectionException::class);
        $transport->write("PING\r\n");
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testClosedStreamReadsRaiseConnectionExceptionNotTypeError(string $class): void
    {
        $transport = $this->transportWithClosedStream($class);

        $this->expectException(ConnectionException::class);
        $transport->read(64);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testClosedStreamIsNotReportedAsConnected(string $class): void
    {
        $transport = $this->transportWithClosedStream($class);

        // Previously feof() on the closed resource raised TypeError here.
        $this->assertFalse($transport->isConnected());
    }

    /**
     * Build a transport holding a stream that has been closed underneath it,
     * standing in for a close() that landed during a yielded socket call.
     *
     * @param class-string $class
     */
    private function transportWithClosedStream(string $class): object
    {
        $transport = new $class();

        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);

        $property = new \ReflectionProperty($class, 'stream');
        $property->setValue($transport, $stream);

        fclose($stream);

        return $transport;
    }
}
