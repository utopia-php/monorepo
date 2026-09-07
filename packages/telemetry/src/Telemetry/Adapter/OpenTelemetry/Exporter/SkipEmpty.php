<?php

namespace Utopia\Telemetry\Adapter\OpenTelemetry\Exporter;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * Drops metrics carrying no data points before they reach the wrapped exporter.
 *
 * An asynchronous instrument whose callback records nothing still reaches the
 * exporter as a metric with an empty data point list. Prometheus answers such a
 * payload with HTTP 500 for the entire OTLP request and rolls back everything
 * already appended, so one empty instrument discards every healthy metric
 * batched alongside it (prometheus/prometheus#19338).
 *
 * ExportingReader probes the exporter for the two optional interfaces below and
 * changes behaviour when either is absent, so both are delegated only when the
 * wrapped exporter provides them. Answering null temporality for an exporter
 * that selects none leaves ExportingReader::add() on its original early return.
 */
final class SkipEmpty implements AggregationTemporalitySelectorInterface, PushMetricExporterInterface
{
    public function __construct(
        private readonly MetricExporterInterface $exporter,
    ) {}

    /**
     * @param iterable<int, Metric> $batch
     */
    public function export(iterable $batch): bool
    {
        $populated = [];
        foreach ($batch as $metric) {
            if (!$this->isEmpty($metric)) {
                $populated[] = $metric;
            }
        }

        if ($populated === []) {
            return true;
        }

        return $this->exporter->export($populated);
    }

    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        return $this->exporter instanceof AggregationTemporalitySelectorInterface
            ? $this->exporter->temporality($metric)
            : null;
    }

    public function forceFlush(): bool
    {
        return $this->exporter instanceof PushMetricExporterInterface
            ? $this->exporter->forceFlush()
            : true;
    }

    public function shutdown(): bool
    {
        return $this->exporter->shutdown();
    }

    private function isEmpty(Metric $metric): bool
    {
        $data = $metric->data;

        if (!$data instanceof Gauge && !$data instanceof Sum && !$data instanceof Histogram) {
            return false;
        }

        return $data->dataPoints === [];
    }
}
