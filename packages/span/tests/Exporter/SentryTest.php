<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Exporter\Sentry;
use Utopia\Span\Exporter\SentryField;
use Utopia\Span\Level;
use Utopia\Span\Span;

class SentryTest extends TestCase
{
    public function testConstructorParsesDsn(): void
    {
        $exporter = new Sentry(dsn: 'https://publickey@sentry.io/123456');

        $this->assertInstanceOf(Sentry::class, $exporter);
    }

    public function testConstructorThrowsOnInvalidDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Sentry DSN');

        new Sentry(dsn: 'http:///invalid');
    }

    public function testConstructorThrowsOnEmptyDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentry DSN is required');

        new Sentry();
    }

    public function testConstructorThrowsOnDsnMissingPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Sentry(dsn: 'https://sentry.io/123');
    }

    public function testConstructorThrowsOnDsnMissingProjectId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Sentry(dsn: 'https://key@sentry.io');
    }

    public function testConstructorHandlesDsnWithPort(): void
    {
        $exporter = new Sentry(dsn: 'https://publickey@sentry.example.com:9000/123');

        $this->assertInstanceOf(Sentry::class, $exporter);
    }

    public function testConstructorHandlesHttpDsn(): void
    {
        $exporter = new Sentry(dsn: 'http://publickey@localhost/123');

        $this->assertInstanceOf(Sentry::class, $exporter);
    }

    public function testExportDoesNotThrowWithValidSpan(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->set('action', 'test');
        $span->finish();

        // Export will fail to connect but should not throw
        // (curl will timeout but we catch that)
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesSpanWithParentId(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->set('span.parent_id', 'abc123def456');
        $span->finish();

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesSpanWithError(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->setError(new \RuntimeException('Test error'));
        $span->finish();

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesSpanWithAllAttributes(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->set('action', 'http.request');
        $span->set('user.id', '123');
        $span->set('request.method', 'POST');
        $span->set('response.status', 200);
        $span->set('span.parent_id', 'parent123');
        $span->finish();

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesHttpConventionAttributes(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span('http.request');
        $span->set('http.url', 'https://api.example.com/users');
        $span->set('http.method', 'POST');
        $span->set('http.query', 'page=1&limit=10');
        $span->set('http.response.status_code', 201);
        $span->setError(new \RuntimeException('Request failed'));
        $span->finish();

        // Should not throw - HTTP attributes are mapped to Sentry request/response
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testSampleSkipsInfoLevelSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->finish();

        $this->assertFalse($exporter->sample($span));
    }

    public function testSampleSendsWarningLevelSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->finish(level: Level::Warn, error: new \RuntimeException('Heads up'));

        $this->assertTrue($exporter->sample($span));
    }

    public function testSampleSendsErrorLevelSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->setError(new \RuntimeException('Boom'));
        $span->finish();

        $this->assertTrue($exporter->sample($span));
    }

    public function testSampleSkipsDowngradedErrorSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->finish(level: Level::Info, error: new \RuntimeException('Handled, not worth reporting'));

        $this->assertFalse($exporter->sample($span));
    }

    public function testSampleComposesCustomSamplerWithLevelFilter(): void
    {
        $exporter = new Sentry(
            sampler: fn(Span $span): bool => $span->getAction() === 'keep',
            dsn: 'https://key@sentry.io/123',
        );

        $kept = new Span('keep');
        $kept->finish(level: Level::Warn, error: new \RuntimeException('Test'));

        $dropped = new Span('drop');
        $dropped->finish(level: Level::Warn, error: new \RuntimeException('Test'));

        $this->assertTrue($exporter->sample($kept));
        $this->assertFalse($exporter->sample($dropped));
    }

    public function testExportWithClassifier(): void
    {
        $exporter = new Sentry(
            dsn: 'https://key@sentry.io/123',
            classifier: fn(string $key): SentryField => match (true) {
                str_starts_with($key, 'tenant.') => SentryField::Tag,
                str_starts_with($key, 'user.') => SentryField::Context,
                default => SentryField::Extra,
            },
        );

        $span = new Span('api.request');
        $span->set('tenant.id', 'acme-corp');
        $span->set('user.id', '12345');
        $span->set('user.email', 'test@example.com');
        $span->set('debug.info', 'some debug data');
        $span->setError(new \RuntimeException('Test error'));
        $span->finish();

        // Should not throw - classifier distributes attributes correctly
        $exporter->export($span);

        $this->assertTrue(true);
    }
}
