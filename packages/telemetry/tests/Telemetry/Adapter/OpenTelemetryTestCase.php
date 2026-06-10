<?php

namespace Tests\Telemetry\Adapter;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\OpenTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

/**
 * Abstract test case for OpenTelemetry adapter.
 *
 * Extend this class and implement getTransport() to test with different transports.
 * This enables easy testing of the adapter with Swoole, default HTTP, or mock transports.
 */
abstract class OpenTelemetryTestCase extends TestCase
{
    protected OpenTelemetry $adapter;

    protected TransportInterface $transport;

    /**
     * Create and return the transport to use for testing.
     * Subclasses must implement this to provide their specific transport.
     */
    abstract protected function createTransport(): TransportInterface;

    /**
     * Get the endpoint URL for the transport.
     * Override in subclasses if needed.
     */
    protected function getEndpoint(): string
    {
        return 'http://localhost:4318/v1/metrics';
    }

    /**
     * Get service configuration for tests.
     *
     * @return array{namespace: string, name: string, instanceId: string}
     */
    protected function getServiceConfig(): array
    {
        return [
            'namespace' => 'test-namespace',
            'name' => 'test-service',
            'instanceId' => 'test-instance-'.uniqid(),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->createTransport();
        $config = $this->getServiceConfig();

        $this->adapter = new OpenTelemetry(
            endpoint: $this->getEndpoint(),
            serviceNamespace: $config['namespace'],
            serviceName: $config['name'],
            serviceInstanceId: $config['instanceId'],
            transport: $this->transport,
        );
    }

    public function testCreateCounter(): void
    {
        $counter = $this->adapter->createCounter(
            name: 'test_counter',
            unit: 'requests',
            description: 'Test counter metric'
        );

        $this->assertInstanceOf(Counter::class, $counter);
    }

    public function testCounterAdd(): void
    {
        $counter = $this->adapter->createCounter('add_test_counter');

        // Should not throw
        $counter->add(1);
        $counter->add(5);
        $counter->add(10, ['label' => 'value']);

        $this->assertTrue(true);
    }

    public function testCreateHistogram(): void
    {
        $histogram = $this->adapter->createHistogram(
            name: 'test_histogram',
            unit: 'ms',
            description: 'Test histogram metric'
        );

        $this->assertInstanceOf(Histogram::class, $histogram);
    }

    public function testHistogramRecord(): void
    {
        $histogram = $this->adapter->createHistogram('record_test_histogram');

        // Should not throw
        $histogram->record(100);
        $histogram->record(250.5);
        $histogram->record(50, ['endpoint' => '/api/test']);

        $this->assertTrue(true);
    }

    public function testCreateGauge(): void
    {
        $gauge = $this->adapter->createGauge(
            name: 'test_gauge',
            unit: 'bytes',
            description: 'Test gauge metric'
        );

        $this->assertInstanceOf(Gauge::class, $gauge);
    }

    public function testGaugeRecord(): void
    {
        $gauge = $this->adapter->createGauge('record_test_gauge');

        // Should not throw
        $gauge->record(1024);
        $gauge->record(2048.5);
        $gauge->record(512, ['host' => 'server-1']);

        $this->assertTrue(true);
    }

    public function testCreateUpDownCounter(): void
    {
        $upDownCounter = $this->adapter->createUpDownCounter(
            name: 'test_updown_counter',
            unit: 'connections',
            description: 'Test up-down counter metric'
        );

        $this->assertInstanceOf(UpDownCounter::class, $upDownCounter);
    }

    public function testUpDownCounterAdd(): void
    {
        $upDownCounter = $this->adapter->createUpDownCounter('add_test_updown');

        // Should not throw
        $upDownCounter->add(1);
        $upDownCounter->add(-1);
        $upDownCounter->add(5, ['pool' => 'main']);

        $this->assertTrue(true);
    }

    public function testCreateObservableGauge(): void
    {
        $gauge = $this->adapter->createObservableGauge(
            name: 'test_observable_gauge',
            unit: 'bytes',
            description: 'Test observable gauge metric'
        );

        $this->assertInstanceOf(ObservableGauge::class, $gauge);
    }

    public function testObservableGaugeObserve(): void
    {
        $gauge = $this->adapter->createObservableGauge('observe_test_gauge');

        // Should not throw
        $gauge->observe(function (callable $observer): void {
            $observer(1024);
            $observer(2048.5, ['host' => 'server-1']);
        });

        $this->assertTrue(true);
    }

    public function testObservableGaugeCaching(): void
    {
        $gauge1 = $this->adapter->createObservableGauge('cached_observable_gauge');
        $gauge2 = $this->adapter->createObservableGauge('cached_observable_gauge');

        $this->assertSame($gauge1, $gauge2);
    }

    public function testMeterCaching(): void
    {
        $counter1 = $this->adapter->createCounter('cached_counter');
        $counter2 = $this->adapter->createCounter('cached_counter');

        // Same name should return cached instance
        $this->assertSame($counter1, $counter2);
    }

    public function testDifferentMetersNotCached(): void
    {
        $counter1 = $this->adapter->createCounter('counter_a');
        $counter2 = $this->adapter->createCounter('counter_b');

        // Different names should return different instances
        $this->assertNotSame($counter1, $counter2);
    }

    public function testCollect(): void
    {
        $counter = $this->adapter->createCounter('collect_test_counter');
        $counter->add(1);

        $result = $this->adapter->collect();

        $this->assertIsBool($result);
    }

    public function testMetricsWithAttributes(): void
    {
        $counter = $this->adapter->createCounter('attributed_counter');
        $histogram = $this->adapter->createHistogram('attributed_histogram');
        $gauge = $this->adapter->createGauge('attributed_gauge');
        $upDownCounter = $this->adapter->createUpDownCounter('attributed_updown');

        $attributes = [
            'service' => 'api',
            'environment' => 'test',
            'version' => '1.0.0',
        ];

        // All should accept attributes without throwing
        $counter->add(1, $attributes);
        $histogram->record(100, $attributes);
        $gauge->record(50, $attributes);
        $upDownCounter->add(1, $attributes);

        $this->assertTrue(true);
    }

    public function testMetricsWithAdvisory(): void
    {
        // Create metrics with advisory parameters
        $counter = $this->adapter->createCounter(
            name: 'advisory_counter',
            unit: 'items',
            description: 'Counter with advisory',
            advisory: ['explicit_bucket_boundaries' => [0, 5, 10, 25, 50, 100]]
        );

        $this->assertInstanceOf(Counter::class, $counter);
    }

    public function testNullOptionalParameters(): void
    {
        // All optional parameters as null
        $counter = $this->adapter->createCounter('minimal_counter');
        $histogram = $this->adapter->createHistogram('minimal_histogram');
        $gauge = $this->adapter->createGauge('minimal_gauge');
        $upDownCounter = $this->adapter->createUpDownCounter('minimal_updown');
        $observableGauge = $this->adapter->createObservableGauge('minimal_observable_gauge');

        $this->assertInstanceOf(Counter::class, $counter);
        $this->assertInstanceOf(Histogram::class, $histogram);
        $this->assertInstanceOf(Gauge::class, $gauge);
        $this->assertInstanceOf(UpDownCounter::class, $upDownCounter);
        $this->assertInstanceOf(ObservableGauge::class, $observableGauge);
    }
}
