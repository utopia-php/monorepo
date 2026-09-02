<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * What Consumer::fetch() puts on the wire, and what it accepts back.
 *
 * Both halves are load-bearing and neither shows up as an error. A pull request
 * that contradicts itself still returns messages, only slowly; a status frame
 * mistaken for data still returns an object, only an empty one that fails in the
 * caller rather than here.
 */
final class ConsumerPullRequestTest extends TestCase
{
    private function connect(FakeTransport $fake): Connection
    {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            transportFactory: fn(string $scheme): FakeTransport => $fake,
        ));
    }

    private function consumer(Connection $conn): Consumer
    {
        return new Consumer($conn, 'STREAM', ConsumerInfo::fromArray([
            'stream_name' => 'STREAM',
            'name' => 'durable',
        ]));
    }

    /**
     * The JSON body of the pull request the client published.
     *
     * @return array<string, mixed>
     */
    private function pullRequest(FakeTransport $fake): array
    {
        $matched = preg_match(
            '#PUB \$JS\.API\.CONSUMER\.MSG\.NEXT\.STREAM\.durable \S+ \d+\r\n(\{.*?\})\r\n#s',
            $fake->written,
            $m,
        );
        $this->assertSame(1, $matched, 'No pull request was published');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testNoWaitPullRequestCarriesNoExpiry(): void
    {
        // The server honours the expiry over no_wait: given both, it waits out
        // the window and answers 408 instead of answering 404 at once, which
        // makes a no_wait poll cost the full timeout it was meant to avoid.
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $consumer->fetch(1, 0.02, true);

        $request = $this->pullRequest($fake);

        $this->assertTrue($request['no_wait'] ?? null);
        $this->assertArrayNotHasKey('expires', $request);
    }

    public function testWaitingPullRequestStillCarriesAnExpiry(): void
    {
        // The other half of the same decision: without no_wait the server needs
        // the expiry, or it holds the request for its own default.
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $consumer->fetch(1, 0.02);

        $request = $this->pullRequest($fake);

        $this->assertArrayNotHasKey('no_wait', $request);
        $this->assertSame(20_000_000, $request['expires']);
    }

    public function testBatchSizeAndMaxBytesSurviveTheNoWaitPath(): void
    {
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $consumer->fetch(7, 0.02, true, 4096);

        $request = $this->pullRequest($fake);

        $this->assertSame(7, $request['batch']);
        $this->assertSame(4096, $request['max_bytes']);
        $this->assertTrue($request['no_wait'] ?? null);
        $this->assertArrayNotHasKey('expires', $request);
    }
}
