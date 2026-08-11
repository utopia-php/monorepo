<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\ObjectStoreException;
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

    public function testSingleWriterOverwriteReclaimsOldChunks(): void
    {
        // Each version is a single chunk. After overwrite, only the new chunk
        // plus one rolled-up meta record should remain (2 messages total);
        // a leftover previous chunk would push the count to 3.
        $this->store->put('reclaim.bin', 'version-one');
        $this->store->put('reclaim.bin', 'version-two');

        $this->assertSame('version-two', $this->store->get('reclaim.bin'));
        $this->assertSame(2, $this->store->status()->state->messages);
    }

    public function testConcurrentOverwriteConflictsInsteadOfOrphaning(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the concurrent-writer race.');
        }

        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
        $chunkSize = 128 * 1024;
        // 2 MB => exactly 16 chunks; the wide chunk-publish window guarantees both
        // writers read the meta sequence (0, object absent) before either publishes
        // its meta, so exactly one loses the optimistic-concurrency check.
        $payload = random_bytes(16 * $chunkSize);
        $expectedChunks = 16;

        $resultFile = tempnam(sys_get_temp_dir(), 'nats_race_');
        $this->assertNotFalse($resultFile);

        // Fixed wall-clock barrier both writers spin to before calling put().
        $barrier = microtime(true) + 0.5;

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child: its own connection, no reuse of the parent's socket.
            $outcome = 'error';
            try {
                $conn = Connection::connect($url);
                $store = new ObjectStore($conn, $conn->jetStream(), $this->bucket);
                $delay = $barrier - microtime(true);
                if ($delay > 0) {
                    usleep((int) ($delay * 1_000_000));
                }
                $store->put('race.bin', $payload);
                $outcome = 'success';
            } catch (ObjectStoreException) {
                $outcome = 'conflict';
            } catch (\Throwable) {
                $outcome = 'error';
            }
            file_put_contents($resultFile, $outcome);
            // Hard-exit so no inherited destructor writes to the parent's shared socket.
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Parent writer.
        $parentOutcome = 'error';
        try {
            $delay = $barrier - microtime(true);
            if ($delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }
            $this->store->put('race.bin', $payload);
            $parentOutcome = 'success';
        } catch (ObjectStoreException) {
            $parentOutcome = 'conflict';
        }

        pcntl_waitpid($pid, $status);
        $childOutcome = (string) file_get_contents($resultFile);
        @unlink($resultFile);

        $outcomes = [$parentOutcome, $childOutcome];
        sort($outcomes);
        $this->assertSame(['conflict', 'success'], $outcomes, "unexpected race outcomes: parent={$parentOutcome} child={$childOutcome}");

        // The winning object is fully intact: get() reassembles and verifies the digest.
        $this->assertSame($payload, $this->store->get('race.bin'));
        $this->assertCount(1, $this->store->list());

        // No orphaned chunk set from the loser: only the winner's chunks plus the
        // single rolled-up meta remain. The loser purged its own chunks on conflict.
        $this->assertSame($expectedChunks + 1, $this->store->status()->state->messages);
    }
}
