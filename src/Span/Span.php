<?php

namespace Utopia\Span;

use Closure;
use Throwable;
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Storage\Storage;

class Span
{
    private static ?Storage $storage = null;

    /**
     * @var array<array{exporter: Exporter, sampler: ?Closure}>
     */
    private static array $exporters = [];

    /**
     * @var array<string, string|int|float|bool|null>
     */
    private array $attributes = [];

    public function __construct()
    {
        $this->attributes['span.trace_id'] = bin2hex(random_bytes(16));
        $this->attributes['span.id'] = bin2hex(random_bytes(8));
        $this->attributes['span.started_at'] = microtime(true);
    }

    /**
     * Set the storage backend for span context
     */
    public static function setStorage(Storage $storage): void
    {
        self::$storage = $storage;
    }

    /**
     * Add an exporter with optional sampler
     *
     * @param Exporter $exporter The exporter to add
     * @param Closure|null $sampler Optional sampler function: fn(Span): bool
     */
    public static function addExporter(Exporter $exporter, ?Closure $sampler = null): void
    {
        self::$exporters[] = [
            'exporter' => $exporter,
            'sampler' => $sampler,
        ];
    }

    /**
     * Remove all exporters
     */
    public static function resetExporters(): void
    {
        self::$exporters = [];
    }

    /**
     * Reset storage
     */
    public static function resetStorage(): void
    {
        self::$storage = null;
    }

    /**
     * Reset all static state
     */
    public static function reset(): void
    {
        self::$storage = null;
        self::$exporters = [];
    }

    /**
     * Initialize a new span and set it as current
     */
    public static function init(): self
    {
        $span = new self();

        if (self::$storage !== null) {
            self::$storage->set($span);
        }

        return $span;
    }

    /**
     * Get the current span from storage
     */
    public static function current(): ?self
    {
        return self::$storage?->get();
    }

    /**
     * Set an attribute on the current span (static convenience method)
     */
    public static function add(string $key, string|int|float|bool|null $value): void
    {
        self::current()?->set($key, $value);
    }

    /**
     * Capture exception details and set on the current span
     */
    public static function error(Throwable $error): void
    {
        self::current()?->setError($error);
    }

    /**
     * Set an attribute on this span
     */
    public function set(string $key, string|int|float|bool|null $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Capture exception details on this span
     */
    public function setError(Throwable $error): self
    {
        $this->attributes['error.type'] = $error::class;
        $this->attributes['error.message'] = $error->getMessage();
        $this->attributes['error.code'] = $error->getCode();
        $this->attributes['error.file'] = $error->getFile();
        $this->attributes['error.line'] = $error->getLine();
        return $this;
    }

    /**
     * Get an attribute value
     */
    public function get(string $key): string|int|float|bool|null
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get all attributes
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Finish this span and export it
     */
    public function finish(): void
    {
        $finishedAt = microtime(true);
        /** @var float $startedAt */
        $startedAt = $this->attributes['span.started_at'];

        $this->attributes['span.finished_at'] = $finishedAt;
        $this->attributes['span.duration'] = $finishedAt - $startedAt;

        foreach (self::$exporters as $config) {
            try {
                $exporter = $config['exporter'];
                $sampler = $config['sampler'];

                if ($sampler === null || $sampler($this)) {
                    $exporter->export($this);
                }
            } catch (\Throwable) {
                // Tracing should never break the application
            }
        }

        if (self::$storage !== null) {
            self::$storage->set(null);
        }
    }
}
