<?php

namespace Utopia\Span\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

class SpanTest extends TestCase
{
    protected function setUp(): void
    {
        Span::resetExporters();
        Span::setStorage(new Memory());
    }

    public function testConstructorSetsSpanAttributes(): void
    {
        $span = new Span();

        $traceId = $span->get('span.trace_id');
        $spanId = $span->get('span.id');
        $startedAt = $span->get('span.started_at');

        $this->assertIsString($traceId);
        $this->assertIsString($spanId);
        $this->assertIsFloat($startedAt);
        $this->assertSame(32, strlen($traceId));
        $this->assertSame(16, strlen($spanId));
    }

    public function testSetAndGet(): void
    {
        $span = new Span();

        $span->set('key', 'value');

        $this->assertSame('value', $span->get('key'));
    }

    public function testSetReturnsself(): void
    {
        $span = new Span();

        $result = $span->set('key', 'value');

        $this->assertSame($span, $result);
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $span = new Span();

        $this->assertNull($span->get('nonexistent'));
    }

    public function testSetAcceptsString(): void
    {
        $span = new Span();
        $span->set('key', 'string value');

        $this->assertSame('string value', $span->get('key'));
    }

    public function testSetAcceptsInt(): void
    {
        $span = new Span();
        $span->set('key', 42);

        $this->assertSame(42, $span->get('key'));
    }

    public function testSetAcceptsFloat(): void
    {
        $span = new Span();
        $span->set('key', 3.14);

        $this->assertSame(3.14, $span->get('key'));
    }

    public function testSetAcceptsBool(): void
    {
        $span = new Span();
        $span->set('key', true);

        $this->assertSame(true, $span->get('key'));
    }

    public function testSetAcceptsNull(): void
    {
        $span = new Span();
        $span->set('key', null);

        $this->assertNull($span->get('key'));
    }

    public function testGetAttributesReturnsAllAttributes(): void
    {
        $span = new Span();
        $span->set('key1', 'value1');
        $span->set('key2', 'value2');

        $attributes = $span->getAttributes();

        $this->assertSame('value1', $attributes['key1']);
        $this->assertSame('value2', $attributes['key2']);
        $this->assertArrayHasKey('span.trace_id', $attributes);
        $this->assertArrayHasKey('span.id', $attributes);
        $this->assertArrayHasKey('span.started_at', $attributes);
    }

    public function testSetErrorStoresThrowable(): void
    {
        $span = new Span();
        $error = new RuntimeException('Test error', 42);

        $span->setError($error);

        $this->assertSame($error, $span->getError());
    }

    public function testSetErrorReturnsSelf(): void
    {
        $span = new Span();
        $error = new RuntimeException('Test error');

        $result = $span->setError($error);

        $this->assertSame($span, $result);
    }

    public function testFinishSetsFinishedAtAndDuration(): void
    {
        $span = new Span();

        $this->assertNull($span->get('span.finished_at'));
        $this->assertNull($span->get('span.duration'));

        $span->finish();

        $this->assertIsFloat($span->get('span.finished_at'));
        $this->assertIsFloat($span->get('span.duration'));
    }

    public function testFinishCalculatesDurationCorrectly(): void
    {
        $span = new Span();
        usleep(10000); // 10ms
        $span->finish();

        $duration = $span->get('span.duration');
        $this->assertIsFloat($duration);
        $this->assertGreaterThan(0.009, $duration);
        $this->assertLessThan(0.1, $duration);
    }

    public function testFinishExportsToAllExporters(): void
    {
        $exported1 = [];
        $exported2 = [];

        $exporter1 = $this->createExporter($exported1);
        $exporter2 = $this->createExporter($exported2);

        Span::addExporter($exporter1);
        Span::addExporter($exporter2);

        $span = Span::init('test');
        $span->finish();

        $this->assertCount(1, $exported1);
        $this->assertCount(1, $exported2);
    }

    public function testFinishClearsCurrentSpan(): void
    {
        $span = Span::init('test');

        $this->assertSame($span, Span::current());

        $span->finish();

        $this->assertNull(Span::current());
    }

    public function testInitCreatesAndStoresSpan(): void
    {
        $span = Span::init('test');

        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame($span, Span::current());
    }

    public function testCurrentReturnsNullWhenNoSpan(): void
    {
        $this->assertNull(Span::current());
    }

    public function testAddSetsAttributeOnCurrentSpan(): void
    {
        $span = Span::init('test');

        Span::add('key', 'value');

        $this->assertSame('value', $span->get('key'));
    }

    public function testAddDoesNothingWhenNoCurrentSpan(): void
    {
        // Should not throw
        Span::add('key', 'value');

        $this->assertNull(Span::current());
    }

    public function testFinishAcceptsError(): void
    {
        $span = new Span();
        $error = new RuntimeException('Test');

        $span->finish($error);

        $this->assertSame($error, $span->getError());
    }

    public function testFinishWithErrorSetsLevelError(): void
    {
        $span = new Span();

        $span->finish(new RuntimeException('Test'));

        $this->assertSame('error', $span->get('level'));
    }

    public function testFinishWithErrorExportsErrorSpan(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);
        $error = new RuntimeException('Test');

        Span::addExporter($exporter);

        $span = Span::init('test');
        $span->finish($error);

        $this->assertCount(1, $exported);
        $this->assertSame($error, $exported[0]->getError());
    }

    public function testSamplerFiltersExport(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        // Only export spans with errors
        Span::addExporter($exporter, fn (Span $s): bool => $s->getError() instanceof \Throwable);

        $span1 = Span::init('test');
        $span1->finish();

        $span2 = Span::init('test');
        $span2->setError(new RuntimeException('Error'));
        $span2->finish();

        $this->assertCount(1, $exported);
    }

    public function testSamplerReceivesSpan(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);
        $sampledSpan = null;

        Span::addExporter($exporter, function (Span $s) use (&$sampledSpan): bool {
            $sampledSpan = $s;
            return true;
        });

        $span = Span::init('test');
        $span->finish();

        $this->assertSame($span, $sampledSpan);
    }

    public function testResetExportersRemovesAllExporters(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter);
        Span::resetExporters();

        $span = Span::init('test');
        $span->finish();

        $this->assertCount(0, $exported);
    }

    public function testFluentInterface(): void
    {
        $span = new Span();

        $result = $span
            ->set('key1', 'value1')
            ->set('key2', 'value2')
            ->setError(new RuntimeException('Error'));

        $this->assertSame($span, $result);
    }

    public function testResetClearsStorageAndExporters(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter);
        $span = Span::init('test');

        $this->assertSame($span, Span::current());

        Span::reset();

        $this->assertNull(Span::current());

        $span2 = Span::init('test');
        $span2->finish();

        $this->assertCount(0, $exported);
    }

    public function testResetStorageClearsOnlyStorage(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter);
        Span::init('test');

        Span::resetStorage();

        $this->assertNull(Span::current());

        Span::setStorage(new Memory());
        $span = Span::init('test');
        $span->finish();

        $this->assertCount(1, $exported);
    }

    public function testInitWithoutStorageReturnsSpan(): void
    {
        Span::resetStorage();

        $span = Span::init('test');

        $this->assertInstanceOf(Span::class, $span);
    }

    public function testCurrentWithoutStorageReturnsNull(): void
    {
        Span::resetStorage();

        $this->assertNull(Span::current());
    }

    public function testSetOverwritesExistingAttribute(): void
    {
        $span = new Span();
        $span->set('key', 'value1');
        $span->set('key', 'value2');

        $this->assertSame('value2', $span->get('key'));
    }

    public function testMultipleSpansInSequence(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);
        Span::addExporter($exporter);

        $span1 = Span::init('test');
        $span1->set('name', 'first');
        $span1->finish();

        $span2 = Span::init('test');
        $span2->set('name', 'second');
        $span2->finish();

        $this->assertCount(2, $exported);
        $this->assertSame('first', $exported[0]->get('name'));
        $this->assertSame('second', $exported[1]->get('name'));
    }

    public function testFinishWithoutExportersDoesNotThrow(): void
    {
        Span::resetExporters();

        $span = Span::init('test');
        $span->finish();

        $this->assertNull(Span::current());
    }

    public function testTraceIdIsUniqueBetweenSpans(): void
    {
        $span1 = new Span();
        $span2 = new Span();

        $this->assertNotEquals(
            $span1->get('span.trace_id'),
            $span2->get('span.trace_id')
        );
    }

    public function testSpanIdIsUniqueBetweenSpans(): void
    {
        $span1 = new Span();
        $span2 = new Span();

        $this->assertNotEquals(
            $span1->get('span.id'),
            $span2->get('span.id')
        );
    }

    public function testCanOverwriteBuiltInAttributes(): void
    {
        $span = new Span();
        $customTraceId = 'custom-trace-id-12345678';

        $span->set('span.trace_id', $customTraceId);

        $this->assertSame($customTraceId, $span->get('span.trace_id'));
    }

    public function testSetParentId(): void
    {
        $span = new Span();
        $parentId = 'abc123def456';

        $span->set('span.parent_id', $parentId);

        $this->assertSame($parentId, $span->get('span.parent_id'));
    }

    public function testAddWithAllScalarTypes(): void
    {
        $span = Span::init('test');

        Span::add('string', 'value');
        Span::add('int', 42);
        Span::add('float', 3.14);
        Span::add('bool', false);
        Span::add('null', null);

        $this->assertSame('value', $span->get('string'));
        $this->assertSame(42, $span->get('int'));
        $this->assertSame(3.14, $span->get('float'));
        $this->assertFalse($span->get('bool'));
        $this->assertNull($span->get('null'));
    }

    public function testMultipleSamplersAllMustPass(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter, fn (Span $s): bool => true);
        Span::addExporter($exporter, fn (Span $s): bool => false);

        $span = Span::init('test');
        $span->finish();

        $this->assertCount(1, $exported);
    }

    public function testSamplerCanFilterByDuration(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter, fn (Span $s): bool => $s->get('span.duration') > 0.005);

        $fastSpan = Span::init('test');
        $fastSpan->finish();

        $slowSpan = Span::init('test');
        usleep(6000);
        $slowSpan->finish();

        $this->assertCount(1, $exported);
    }

    public function testGetTraceparentReturnsValidFormat(): void
    {
        $span = new Span();

        $traceparent = $span->getTraceparent();

        $parts = explode('-', $traceparent);
        $this->assertCount(4, $parts);
        $this->assertSame('00', $parts[0]);
        $this->assertSame(32, strlen($parts[1]));
        $this->assertSame(16, strlen($parts[2]));
        $this->assertSame('01', $parts[3]);
    }

    public function testGetTraceparentUsesSpanAttributes(): void
    {
        $span = new Span();
        $traceId = $span->get('span.trace_id');
        $spanId = $span->get('span.id');

        $traceparent = $span->getTraceparent();

        $this->assertSame("00-{$traceId}-{$spanId}-01", $traceparent);
    }

    public function testTraceparentRoundTrip(): void
    {
        $span1 = new Span();
        $traceparent = $span1->getTraceparent();

        $span2 = Span::init('test', $traceparent);

        $this->assertSame($span1->get('span.trace_id'), $span2->get('span.trace_id'));
        $this->assertSame($span1->get('span.id'), $span2->get('span.parent_id'));
    }

    public function testStaticTraceparentReturnsCurrentSpanTraceparent(): void
    {
        $span = Span::init('test');

        $traceparent = Span::traceparent();

        $this->assertSame($span->getTraceparent(), $traceparent);
    }

    public function testStaticTraceparentReturnsNullWhenNoCurrentSpan(): void
    {
        $this->assertNull(Span::traceparent());
    }

    public function testInitWithTraceparentSetsTraceIdAndParentId(): void
    {
        $traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

        $span = Span::init('test', $traceparent);

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $span->get('span.trace_id'));
        $this->assertSame('b7ad6b7169203331', $span->get('span.parent_id'));
        $this->assertSame($span, Span::current());
    }

    public function testInitWithNullTraceparentGeneratesNewTraceId(): void
    {
        $span = Span::init('test');

        $traceId = $span->get('span.trace_id');
        $this->assertIsString($traceId);
        $this->assertSame(32, strlen($traceId));
        $this->assertNull($span->get('span.parent_id'));
    }

    public function testInitWithInvalidTraceparentCreatesNewTrace(): void
    {
        $span = Span::init('test', 'invalid-traceparent');

        $traceId = $span->get('span.trace_id');
        $this->assertIsString($traceId);
        $this->assertSame(32, strlen($traceId));
        $this->assertNull($span->get('span.parent_id'));
    }

    public function testFinishSetsLevelInfoByDefault(): void
    {
        $span = new Span();
        $span->finish();

        $this->assertSame('info', $span->get('level'));
    }

    public function testFinishSetsLevelErrorWhenErrorSet(): void
    {
        $span = new Span();
        $span->setError(new RuntimeException('Test'));
        $span->finish();

        $this->assertSame('error', $span->get('level'));
    }

    public function testFinishDoesNotOverrideExplicitLevel(): void
    {
        $span = new Span();
        $span->set('level', 'warning');
        $span->setError(new RuntimeException('Test'));
        $span->finish();

        $this->assertSame('warning', $span->get('level'));
    }

    public function testLevelNotSetBeforeFinish(): void
    {
        $span = new Span();

        $this->assertNull($span->get('level'));
    }

    /**
     * @param array<Span> $exported
     */
    private function createExporter(array &$exported): Exporter
    {
        return new class ($exported) implements Exporter {
            /** @var array<Span> */
            private array $exported;

            /** @param array<Span> $exported */
            public function __construct(array &$exported)
            {
                $this->exported = &$exported;
            }

            public function export(Span $span): void
            {
                $this->exported[] = $span;
            }
        };
    }
}
