<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\ObjectStore\ObjectStoreConfig;

final class ObjectStoreTest extends TestCase
{
    private Connection $conn;
    private JetStream $js;
    private ObjectStore $store;
    private string $bucket;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
        $this->conn = Connection::connect($url);
        $this->js = $this->conn->jetStream();
        $this->bucket = 'obj_' . uniqid();
        $this->store = ObjectStore::createOrUpdate($this->conn, $this->js, new ObjectStoreConfig(
            bucket: $this->bucket,
        ));
    }

    protected function tearDown(): void
    {
        try {
            $this->js->deleteStream("OBJ_{$this->bucket}");
        } catch (\Throwable) {
            // ignore
        }
        $this->conn->close();
    }

    public function testRoundTripMultiChunkPayload(): void
    {
        // ~300KB => spans multiple 128KB chunks.
        $payload = random_bytes(300 * 1024);

        $putMeta = $this->store->put('big.bin', $payload);
        $this->assertGreaterThan(1, $putMeta->chunks);
        $this->assertSame(\strlen($payload), $putMeta->size);

        $fetched = $this->store->get('big.bin');
        $this->assertSame($payload, $fetched);

        $meta = $this->store->getMeta('big.bin');
        $this->assertSame($putMeta->digest, $meta->digest);

        // Digest matches an independent computation.
        $expectedDigest = 'SHA-256=' . rtrim(strtr(base64_encode(hash('sha256', $payload, true)), '+/', '-_'), '=');
        $this->assertSame($expectedDigest, $meta->digest);
    }

    public function testSmallObjectRoundTrip(): void
    {
        $this->store->put('hello.txt', 'hello world');
        $this->assertSame('hello world', $this->store->get('hello.txt'));
    }

    public function testListReturnsStoredObjects(): void
    {
        $this->store->put('a.txt', 'aaa');
        $this->store->put('b.txt', 'bbb');

        $names = array_map(fn(\Utopia\NATS\ObjectStore\ObjectMeta $m): string => $m->name, $this->store->list());
        sort($names);

        $this->assertSame(['a.txt', 'b.txt'], $names);
    }

    public function testDeleteRemovesObject(): void
    {
        $this->store->put('temp.txt', 'gone soon');
        $this->assertSame('gone soon', $this->store->get('temp.txt'));

        $this->store->delete('temp.txt');

        $this->expectException(\RuntimeException::class);
        $this->store->get('temp.txt');
    }

    public function testOverwriteReplacesData(): void
    {
        $this->store->put('file', 'version-one');
        $this->store->put('file', 'version-two-longer');

        $this->assertSame('version-two-longer', $this->store->get('file'));
        $this->assertCount(1, $this->store->list());
    }

    public function testStatusReportsBucketStream(): void
    {
        $this->store->put('x.txt', 'data');
        $info = $this->store->status();
        $this->assertSame("OBJ_{$this->bucket}", $info->config->name);
        $this->assertGreaterThan(0, $info->state->messages);
    }
}
