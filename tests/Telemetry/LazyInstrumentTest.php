<?php

namespace Tests\Telemetry;

use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\Test;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;

class LazyInstrumentTest extends TestCase
{
    public function testLazyCounterCreatesInnerCounterOnFirstAdd(): void
    {
        $telemetry = new Test();
        $counter = Counter::lazy($telemetry, 'events.total', '{event}', 'Event count');

        $this->assertSame([], $telemetry->counters);

        $counter->add(1, ['event.name' => 'created']);

        $this->assertArrayHasKey('events.total', $telemetry->counters);
        $this->assertSame([1], $telemetry->counters['events.total']->values);

        $inner = $telemetry->counters['events.total'];
        $counter->add(2);

        $this->assertSame($inner, $telemetry->counters['events.total']);
        $this->assertSame([1, 2], $telemetry->counters['events.total']->values);
    }

    public function testLazyGaugeCreatesInnerGaugeOnFirstRecord(): void
    {
        $telemetry = new Test();
        $gauge = Gauge::lazy($telemetry, 'event.timestamp', 's', 'Event timestamp');

        $this->assertSame([], $telemetry->gauges);

        $gauge->record(123.45, ['event.name' => 'transition']);

        $this->assertArrayHasKey('event.timestamp', $telemetry->gauges);
        $this->assertSame([123.45], $telemetry->gauges['event.timestamp']->values);

        $inner = $telemetry->gauges['event.timestamp'];
        $gauge->record(456.78);

        $this->assertSame($inner, $telemetry->gauges['event.timestamp']);
        $this->assertSame([123.45, 456.78], $telemetry->gauges['event.timestamp']->values);
    }
}
