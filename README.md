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
$span = Span::init('http.request');
$span->set('user.id', '123');
$span->finish();
```

## Usage

### Setting Attributes

Everything is a flat key-value attribute. Only scalar types are allowed (string, int, float, bool, null):

```php
$span = Span::init('api.request');
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
| `level`            | `error` if error set, `info` otherwise |

### Static Helpers

Use static methods anywhere in your codebase without passing the span around:

```php
// Set attribute on current span
Span::add('db.query_count', 5);

// Add warning details as attributes
Span::add('warning', 'retry scheduled');
```

### Error Handling

Pass the exception to `finish()` when the span ends because of an error:

```php
$span = Span::init('api.request');

try {
    // ...
} catch (Throwable $e) {
    $span->finish(error: $e);
    throw $e;
}
```

Exporters access the exception via `$span->getError()` and extract what they need (message, trace, etc.).

Use `setError()` when you need to record the error before the span ends, such as before cleanup work that should still be included in the same span.

The `level` attribute is set when the span finishes. It defaults to `error` when an error is captured and `info` otherwise. Pass a level to `finish()` to override it:

```php
$span->finish(level: 'warning', error: $e);
```

Use attributes for warning details that do not end the span:

```php
Span::add('warning', 'retry scheduled');
Span::add('warning.message', $message);
```

### Distributed Tracing

Propagate trace context across services using W3C Trace Context headers:

```php
// Service A: outgoing request
$client->post('/api/downstream', $payload, [
    'traceparent' => Span::traceparent(),
]);

// Service B: incoming request
$span = Span::init('http.request', $request->getHeader('traceparent'));
```

### Sampling

Add a sampler to control which spans get exported:

```php
Span::addExporter(
    new Exporter\Sentry('https://key@sentry.io/123'),
    sampler: fn(Span $s) =>
        $s->getError() !== null ||          // errors
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

| Exporter           | Description                          |
| ------------------ | ------------------------------------ |
| `Exporter\Stdout`  | JSON to stdout/stderr                |
| `Exporter\Pretty`  | Colourful human-readable output      |
| `Exporter\Sentry`  | Sentry events (Issues)               |
| `Exporter\None`    | Discard (for testing)                |

### Stdout Exporter

```php
Span::addExporter(new Exporter\Stdout(
    maxTraceFrames: 3  // default, limits error stacktrace length
));
```

Outputs JSON to stdout (info) or stderr (errors).

### Pretty Exporter

```php
Span::addExporter(new Exporter\Pretty(
    maxTraceFrames: 3,  // default, limits error stacktrace length
    width: 60           // default, separator line width
));
```

Colourful, multi-line output for local development. Attributes are displayed with aligned values, duration is colour-coded (green < 100ms, yellow < 1s, red >= 1s), and errors are highlighted in red. Writes to stdout (info) or stderr (errors).

```
http.request · 12.3ms · abc12345

  http.method GET
  http.url    /api/users
  user.id     42

────────────────────────────────────────────────────────────
```

### Sentry Exporter

```php
Span::addExporter(new Exporter\Sentry(
    dsn: 'https://key@sentry.io/123',
    environment: 'production'  // optional
));
```

Only exports error spans with full stacktraces. Non-error spans are skipped.

### Custom Exporter

```php
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

class MyExporter implements Exporter
{
    public function export(Span $span): void
    {
        $data = $span->getAttributes();
        $error = $span->getError();
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
| `init(string $action, ?string $traceparent): Span`   | Create and store a new span           |
| `current(): ?Span`                                   | Get the current span                  |
| `add(string $key, scalar $value)`                    | Set attribute on current span         |
| `traceparent(): ?string`                             | Get traceparent header from current span |

### Span (instance)

| Method                                  | Description                        |
| --------------------------------------- | ---------------------------------- |
| `set(string $key, scalar $value): self` | Set an attribute                   |
| `get(string $key): scalar`              | Get an attribute                   |
| `getAttributes(): array`                | Get all attributes                 |
| `getAction(): string`                   | Get the span action                |
| `setError(Throwable $e): self`          | Capture exception                  |
| `getError(): ?Throwable`                | Get captured exception             |
| `getTraceparent(): string`              | Get W3C traceparent header value   |
| `finish(?string $level = null, ?Throwable $error = null): void` | End span and export |

### Attribute Conventions

| Prefix   | Description            |
| -------- | ---------------------- |
| `span.*` | Built-in span metadata |
| `*`      | User-defined           |

## License

MIT
