<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Telemetry;
use Utopia\Storage\Storage;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class StorageTest extends TestCase
{
    public function testHuman(): void
    {
        $this->assertSame('1.00kB', Storage::human(1000));
        $this->assertSame('1.00KiB', Storage::human(1024, system: 'binary'));
        $this->assertSame('2.5MB', Storage::human(2500000, 1));
    }

    public function testMoveIdenticalName(): void
    {
        $file = '/kitten-1.jpg';
        $device = new Local(__DIR__ . '/../resources/disk-a');
        $this->assertFalse($device->move($file, $file));
    }

    public function testStorageOperationTelemetryIsCreatedOnFirstRecord(): void
    {
        $telemetry = new TestTelemetry();
        $underlying = new Local(__DIR__ . '/../resources/disk-a');
        $device = new Telemetry($telemetry, $underlying);
        $path = $underlying->getPath('lorem.txt');

        $this->assertArrayNotHasKey('storage.operation', $telemetry->histograms);

        $device->exists($path);

        $this->assertArrayHasKey('storage.operation', $telemetry->histograms);
    }
}
