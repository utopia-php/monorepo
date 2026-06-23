<?php

namespace Utopia\Replication\Tests\Unit\Checkpoint;

use PHPUnit\Framework\TestCase;
use Utopia\Replication\Checkpoint\File;

class FileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/replication-checkpoint-test/' . uniqid('ckpt', true) . '.gtid';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.tmp');
        @rmdir(\dirname($this->path));
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        $checkpoint = new File($this->path);

        $this->assertNull($checkpoint->get());
    }

    public function testSetThenGetRoundTrips(): void
    {
        $checkpoint = new File($this->path, interval: 0.0);
        $checkpoint->set('uuid:1-5');

        $this->assertSame('uuid:1-5', (new File($this->path))->get());
    }

    public function testCreatesParentDirectory(): void
    {
        $checkpoint = new File($this->path, interval: 0.0);
        $checkpoint->set('uuid:1');

        $this->assertDirectoryExists(\dirname($this->path));
    }

    public function testWritesCoalesceUntilFlush(): void
    {
        // A long interval keeps the second write in the window; the file holds
        // the first value until flush() forces the latest out.
        $checkpoint = new File($this->path, interval: 3600.0);
        $checkpoint->set('uuid:1');
        $checkpoint->set('uuid:2');

        $this->assertSame('uuid:1', (new File($this->path))->get());

        $checkpoint->flush();
        $this->assertSame('uuid:2', (new File($this->path))->get());
    }

    public function testEmptyFileReadsAsNull(): void
    {
        mkdir(\dirname($this->path), 0o775, true);
        file_put_contents($this->path, "  \n");

        $this->assertNull((new File($this->path))->get());
    }
}
