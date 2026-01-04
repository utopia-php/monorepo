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
        $this->assertEquals(32, strlen($traceId));
        $this->assertEquals(16, strlen($spanId));
    }

    public function testSetAndGet(): void
    {
        $span = new Span();

        $span->set('key', 'value');

        $this->assertEquals('value', $span->get('key'));
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

        $this->assertEquals('string value', $span->get('key'));
    }

    public function testSetAcceptsInt(): void
    {
        $span = new Span();
        $span->set('key', 42);

        $this->assertEquals(42, $span->get('key'));
    }

    public function testSetAcceptsFloat(): void
    {
        $span = new Span();
        $span->set('key', 3.14);

        $this->assertEquals(3.14, $span->get('key'));
    }

    public function testSetAcceptsBool(): void
    {
        $span = new Span();
        $span->set('key', true);

        $this->assertEquals(true, $span->get('key'));
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

        $this->assertEquals('value1', $attributes['key1']);
        $this->assertEquals('value2', $attributes['key2']);
        $this->assertArrayHasKey('span.trace_id', $attributes);
        $this->assertArrayHasKey('span.id', $attributes);
        $this->assertArrayHasKey('span.started_at', $attributes);
    }

    public function testSetErrorSetsErrorAttributes(): void
    {
        $span = new Span();
        $error = new RuntimeException('Test error', 42);

        $span->setError($error);

        $this->assertEquals(RuntimeException::class, $span->get('error.type'));
        $this->assertEquals('Test error', $span->get('error.message'));
        $this->assertEquals(42, $span->get('error.code'));
        $this->assertIsString($span->get('error.file'));
        $this->assertIsInt($span->get('error.line'));
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

        $span = Span::init();
        $span->finish();

        $this->assertCount(1, $exported1);
        $this->assertCount(1, $exported2);
    }

    public function testFinishClearsCurrentSpan(): void
    {
        $span = Span::init();

        $this->assertSame($span, Span::current());

        $span->finish();

        $this->assertNull(Span::current());
    }

    public function testInitCreatesAndStoresSpan(): void
    {
        $span = Span::init();

        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame($span, Span::current());
    }

    public function testCurrentReturnsNullWhenNoSpan(): void
    {
        $this->assertNull(Span::current());
    }

    public function testAddSetsAttributeOnCurrentSpan(): void
    {
        $span = Span::init();

        Span::add('key', 'value');

        $this->assertEquals('value', $span->get('key'));
    }

    public function testAddDoesNothingWhenNoCurrentSpan(): void
    {
        // Should not throw
        Span::add('key', 'value');

        $this->assertNull(Span::current());
    }

    public function testErrorSetsErrorOnCurrentSpan(): void
    {
        $span = Span::init();

        Span::error(new RuntimeException('Test'));

        $this->assertEquals(RuntimeException::class, $span->get('error.type'));
    }

    public function testErrorDoesNothingWhenNoCurrentSpan(): void
    {
        // Should not throw
        Span::error(new RuntimeException('Test'));

        $this->assertNull(Span::current());
    }

    public function testSamplerFiltersExport(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        // Only export spans with errors
        Span::addExporter($exporter, fn (Span $s) => $s->get('error.type') !== null);

        $span1 = Span::init();
        $span1->finish();

        $span2 = Span::init();
        $span2->setError(new RuntimeException('Error'));
        $span2->finish();

        $this->assertCount(1, $exported);
    }

    public function testSamplerReceivesSpan(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);
        $sampledSpan = null;

        Span::addExporter($exporter, function (Span $s) use (&$sampledSpan) {
            $sampledSpan = $s;
            return true;
        });

        $span = Span::init();
        $span->finish();

        $this->assertSame($span, $sampledSpan);
    }

    public function testResetExportersRemovesAllExporters(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter);
        Span::resetExporters();

        $span = Span::init();
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
        $span = Span::init();

        $this->assertSame($span, Span::current());

        Span::reset();

        $this->assertNull(Span::current());

        $span2 = Span::init();
        $span2->finish();

        $this->assertCount(0, $exported);
    }

    public function testResetStorageClearsOnlyStorage(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter);
        Span::init();

        Span::resetStorage();

        $this->assertNull(Span::current());

        Span::setStorage(new Memory());
        $span = Span::init();
        $span->finish();

        $this->assertCount(1, $exported);
    }

    public function testInitWithoutStorageReturnsSpan(): void
    {
        Span::resetStorage();

        $span = Span::init();

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

        $this->assertEquals('value2', $span->get('key'));
    }

    public function testMultipleSpansInSequence(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);
        Span::addExporter($exporter);

        $span1 = Span::init();
        $span1->set('name', 'first');
        $span1->finish();

        $span2 = Span::init();
        $span2->set('name', 'second');
        $span2->finish();

        $this->assertCount(2, $exported);
        $this->assertEquals('first', $exported[0]->get('name'));
        $this->assertEquals('second', $exported[1]->get('name'));
    }

    public function testFinishWithoutExportersDoesNotThrow(): void
    {
        Span::resetExporters();

        $span = Span::init();
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

        $this->assertEquals($customTraceId, $span->get('span.trace_id'));
    }

    public function testSetParentId(): void
    {
        $span = new Span();
        $parentId = 'abc123def456';

        $span->set('span.parent_id', $parentId);

        $this->assertEquals($parentId, $span->get('span.parent_id'));
    }

    public function testAddWithAllScalarTypes(): void
    {
        $span = Span::init();

        Span::add('string', 'value');
        Span::add('int', 42);
        Span::add('float', 3.14);
        Span::add('bool', false);
        Span::add('null', null);

        $this->assertEquals('value', $span->get('string'));
        $this->assertEquals(42, $span->get('int'));
        $this->assertEquals(3.14, $span->get('float'));
        $this->assertFalse($span->get('bool'));
        $this->assertNull($span->get('null'));
    }

    public function testMultipleSamplersAllMustPass(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter, fn (Span $s) => true);
        Span::addExporter($exporter, fn (Span $s) => false);

        $span = Span::init();
        $span->finish();

        $this->assertCount(1, $exported);
    }

    public function testSamplerCanFilterByDuration(): void
    {
        $exported = [];
        $exporter = $this->createExporter($exported);

        Span::addExporter($exporter, fn (Span $s) => $s->get('span.duration') > 0.005);

        $fastSpan = Span::init();
        $fastSpan->finish();

        $slowSpan = Span::init();
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
        $this->assertEquals('00', $parts[0]);
        $this->assertEquals(32, strlen($parts[1]));
        $this->assertEquals(16, strlen($parts[2]));
        $this->assertEquals('01', $parts[3]);
    }

    public function testGetTraceparentUsesSpanAttributes(): void
    {
        $span = new Span();
        $traceId = $span->get('span.trace_id');
        $spanId = $span->get('span.id');

        $traceparent = $span->getTraceparent();

        $this->assertEquals("00-{$traceId}-{$spanId}-01", $traceparent);
    }

    public function testTraceparentRoundTrip(): void
    {
        $span1 = new Span();
        $traceparent = $span1->getTraceparent();

        $span2 = Span::init($traceparent);

        $this->assertEquals($span1->get('span.trace_id'), $span2->get('span.trace_id'));
        $this->assertEquals($span1->get('span.id'), $span2->get('span.parent_id'));
    }

    public function testStaticTraceparentReturnsCurrentSpanTraceparent(): void
    {
        $span = Span::init();

        $traceparent = Span::traceparent();

        $this->assertEquals($span->getTraceparent(), $traceparent);
    }

    public function testStaticTraceparentReturnsNullWhenNoCurrentSpan(): void
    {
        $this->assertNull(Span::traceparent());
    }

    public function testInitWithTraceparentSetsTraceIdAndParentId(): void
    {
        $traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

        $span = Span::init($traceparent);

        $this->assertEquals('0af7651916cd43dd8448eb211c80319c', $span->get('span.trace_id'));
        $this->assertEquals('b7ad6b7169203331', $span->get('span.parent_id'));
        $this->assertSame($span, Span::current());
    }

    public function testInitWithNullTraceparentGeneratesNewTraceId(): void
    {
        $span = Span::init(null);

        $traceId = $span->get('span.trace_id');
        $this->assertIsString($traceId);
        $this->assertEquals(32, strlen($traceId));
        $this->assertNull($span->get('span.parent_id'));
    }

    public function testInitWithInvalidTraceparentCreatesNewTrace(): void
    {
        $span = Span::init('invalid-traceparent');

        $traceId = $span->get('span.trace_id');
        $this->assertIsString($traceId);
        $this->assertEquals(32, strlen($traceId));
        $this->assertNull($span->get('span.parent_id'));
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
