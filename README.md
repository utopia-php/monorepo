# Utopia Span

A simple, memory-safe span tracing library for PHP with Swoole coroutine support.

## Installation

```bash
composer require utopia-php/span
```

## Quick Start

```php
use Utopia\Span\Span;
use Utopia\Span\Storage;
use Utopia\Span\Exporter;

// Bootstrap once at startup
Span::setStorage(new Storage\Auto());
Span::addExporter(new Exporter\Stdout());

// Create a span
$span = Span::init();
$span->set('action', 'http.request');
$span->set('user.id', '123');
$span->finish();
```

## Usage

### Setting Attributes

Everything is a flat key-value attribute. Only scalar types are allowed (string, int, float, bool, null):

```php
$span = Span::init();
$span->set('service.name', 'api');
$span->set('request.duration_ms', 42.5);
$span->set('request.cached', true);
$span->finish();
```

### Built-in Attributes

Spans automatically include these attributes:

| Attribute          | Description                         |
| ------------------ | ----------------------------------- |
| `span.trace_id`    | Unique trace identifier (32 hex chars) |
| `span.id`          | Unique span identifier (16 hex chars)  |
| `span.started_at`  | Start timestamp in seconds (float)  |
| `span.finished_at` | End timestamp in seconds (float)    |
| `span.duration`    | Duration in seconds (float)         |

### Static Helpers

Use static methods anywhere in your codebase without passing the span around:

```php
// Set attribute on current span
Span::add('db.query_count', 5);

// Capture an exception
Span::error($exception);
```

### Error Handling

The `setError()` helper extracts exception details into scalar attributes:

```php
try {
    // ...
} catch (Throwable $e) {
    $span->setError($e);
    // Sets: error.type, error.message, error.code, error.file, error.line
    throw $e;
}
```

### Distributed Tracing

Propagate trace context across services using W3C Trace Context headers:

```php
// Service A: outgoing request
$span = Span::current();
$traceparent = sprintf(
    '00-%s-%s-01',
    $span->get('span.trace_id'),
    $span->get('span.id')
);
$client->post('/api/downstream', $payload, [
    'traceparent' => $traceparent,
]);

// Service B: incoming request
$traceparent = $request->getHeader('traceparent');
[$version, $traceId, $parentId, $flags] = explode('-', $traceparent);

$span = Span::init();
$span->set('span.trace_id', $traceId);   // continue the trace
$span->set('span.parent_id', $parentId); // link to parent span
```

### Sampling

Add a sampler to control which spans get exported:

```php
Span::addExporter(
    new Exporter\Sentry(dsn: 'https://key@sentry.io/123'),
    sampler: fn(Span $s) =>
        $s->get('error.type') !== null ||   // errors
        $s->get('span.duration') > 5.0 ||   // slow requests (>5s)
        $s->get('plan') === 'enterprise'    // enterprise customers
);
```

## Storage Backends

| Backend             | Use Case                                |
| ------------------- | --------------------------------------- |
| `Storage\Auto`      | Auto-detects best storage (recommended) |
| `Storage\Memory`    | Plain PHP (FPM, CLI)                    |
| `Storage\Coroutine` | Swoole coroutine contexts               |

## Exporters

| Exporter          | Description           |
| ----------------- | --------------------- |
| `Exporter\Stdout` | JSON to stdout        |
| `Exporter\Sentry` | Sentry transactions   |
| `Exporter\None`   | Discard (for testing) |

### Custom Exporter

```php
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

class MyExporter implements Exporter
{
    public function export(Span $span): void
    {
        // All data is in getAttributes()
        $data = $span->getAttributes();
        // Send to your backend
    }
}
```

## Testing

Disable or capture spans in tests:

```php
// Option 1: Discard all spans
Span::resetExporters();
Span::addExporter(new Exporter\None());

// Option 2: Capture for assertions
$spans = [];
Span::addExporter(new class($spans) implements Exporter {
    public function __construct(private array &$spans) {}
    public function export(Span $span): void {
        $this->spans[] = $span;
    }
});

// Run code...

$this->assertCount(1, $spans);
$this->assertEquals('http.request', $spans[0]->get('action'));
```

## API Reference

### Span (static)

| Method                                               | Description                           |
| ---------------------------------------------------- | ------------------------------------- |
| `setStorage(Storage $storage)`                       | Set the storage backend               |
| `addExporter(Exporter $exporter, ?Closure $sampler)` | Add an exporter with optional sampler |
| `resetExporters()`                                   | Remove all exporters                  |
| `init(): Span`                                       | Create and store a new span           |
| `current(): ?Span`                                   | Get the current span                  |
| `add(string $key, scalar $value)`                    | Set attribute on current span         |
| `error(Throwable $e)`                                | Capture exception on current span     |

### Span (instance)

| Method                                  | Description               |
| --------------------------------------- | ------------------------- |
| `set(string $key, scalar $value): self` | Set an attribute          |
| `get(string $key): scalar`              | Get an attribute          |
| `getAttributes(): array`                | Get all attributes        |
| `setError(Throwable $e): self`          | Capture exception details |
| `finish(): void`                        | End span and export       |

### Attribute Conventions

| Prefix    | Description                             |
| --------- | --------------------------------------- |
| `span.*`  | Built-in span metadata                  |
| `error.*` | Exception details (set by `setError()`) |
| `*`       | User-defined attributes                 |

## License

MIT
