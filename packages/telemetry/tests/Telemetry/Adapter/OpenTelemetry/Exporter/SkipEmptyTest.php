<?php

declare(strict_types=1);

namespace Tests\Telemetry\Adapter\OpenTelemetry\Exporter;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\OpenTelemetry;

final class SkipEmptyTest extends TestCase
{
    public function testInstrumentThatRecordsNothingIsNotExported(): void
    {
        $transport = $this->transport();
        $telemetry = new OpenTelemetry('http://localhost:4318/v1/metrics', 'tests', 'skip-empty', 'instance', $transport);

        $telemetry->createObservableGauge('never.observed')->observe(function (callable $observe): void {});
        $telemetry->createCounter('always.counted')->add(1);

        $telemetry->collect();

        $payload = implode('', $transport->payloads);

        $this->assertStringContainsString(
            'always.counted',
            $payload,
            'A populated instrument must still reach the transport',
        );
        $this->assertStringNotContainsString(
            'never.observed',
            $payload,
            'An instrument that recorded nothing carries no data points, and Prometheus answers such a payload with HTTP 500 for the whole request',
        );
    }

    public function testObservationsArePreservedWhenTheCallbackRecords(): void
    {
        $transport = $this->transport();
        $telemetry = new OpenTelemetry('http://localhost:4318/v1/metrics', 'tests', 'skip-empty', 'instance', $transport);

        $telemetry->createObservableGauge('sometimes.observed')->observe(function (callable $observe): void {
            $observe(7, ['database' => 'one']);
        });

        $telemetry->collect();

        $payload = implode('', $transport->payloads);

        $this->assertStringContainsString('sometimes.observed', $payload);
        $this->assertStringContainsString('"asInt":"7"', $payload, 'The recorded value must survive the filter');
    }

    public function testNothingIsSentWhenEveryInstrumentIsEmpty(): void
    {
        $transport = $this->transport();
        $telemetry = new OpenTelemetry('http://localhost:4318/v1/metrics', 'tests', 'skip-empty', 'instance', $transport);

        $telemetry->createObservableGauge('never.observed')->observe(function (callable $observe): void {});

        $telemetry->collect();

        $this->assertSame([], $transport->payloads, 'A batch holding only empty instruments must not be sent at all');
    }

    public function testAnOverriddenExporterIsStillFiltered(): void
    {
        $transport = $this->transport();
        $telemetry = new class ('http://localhost:4318/v1/metrics', 'tests', 'skip-empty', 'instance', $transport) extends OpenTelemetry {
            protected function createExporter(TransportInterface $transport): MetricExporterInterface
            {
                /** @phpstan-ignore argument.type */
                return new MetricExporter($transport, Temporality::DELTA);
            }
        };

        $telemetry->createObservableGauge('never.observed')->observe(function (callable $observe): void {});
        $telemetry->createCounter('always.counted')->add(1);

        $telemetry->collect();

        $payload = implode('', $transport->payloads);

        $this->assertStringContainsString('always.counted', $payload);
        $this->assertStringNotContainsString(
            'never.observed',
            $payload,
            'Filtering must not depend on createExporter() remembering to apply it',
        );
    }

    /**
     * @return TransportInterface<string>&object{payloads: list<string>}
     */
    private function transport(): TransportInterface
    {
        return new class implements TransportInterface {
            /** @var list<string> */
            public array $payloads = [];

            public function contentType(): string
            {
                return ContentTypes::JSON;
            }

            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                $this->payloads[] = $payload;

                return new CompletedFuture(null);
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
    }
}
