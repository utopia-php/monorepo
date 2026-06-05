<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Utopia\Client\Adapter\Curl\Client as CurlClient;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleClient;
use Utopia\Psr7\ResponseFactory;
use Utopia\Psr7\StreamFactory;
use ValueError;

final class TimeoutTest extends TestCase
{
    public function testCurlTimeoutsAreMappedToMillisecondsImmutably(): void
    {
        $adapter = new CurlClient(new ResponseFactory(), new StreamFactory());
        $configured = $adapter
            ->withTimeout(2.5)
            ->withConnectTimeout(1.25);

        $this->assertSame([], $this->property($adapter, 'options'));
        $this->assertSame(2500, $this->property($configured, 'options')[\CURLOPT_TIMEOUT_MS]);
        $this->assertSame(1250, $this->property($configured, 'options')[\CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testSwooleTimeoutsAreMappedToSettingsImmutably(): void
    {
        $adapter = new SwooleClient(new ResponseFactory(), new StreamFactory());
        $configured = $adapter
            ->withTimeout(2.5)
            ->withConnectTimeout(1.25);

        $this->assertSame([], $this->property($adapter, 'settings'));
        $this->assertEqualsWithDelta(2.5, $this->property($configured, 'settings')['timeout'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.25, $this->property($configured, 'settings')['connect_timeout'], PHP_FLOAT_EPSILON);
    }

    public function testInvalidAdapterTimeoutsAreRejected(): void
    {
        $adapter = new CurlClient(new ResponseFactory(), new StreamFactory());

        $this->expectException(ValueError::class);

        $adapter->withTimeout(\INF);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function property(object $object, string $property): array
    {
        $reflection = new ReflectionProperty($object, $property);
        $value = $reflection->getValue($object);

        $this->assertIsArray($value);

        return $value;
    }
}
