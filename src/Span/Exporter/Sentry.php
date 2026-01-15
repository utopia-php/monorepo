<?php

namespace Utopia\Span\Exporter;

use Closure;
use Composer\InstalledVersions;
use Utopia\Span\Span;

/**
 * Exports error spans to Sentry Issues.
 *
 * Only spans with errors are sent. Non-error spans are silently skipped.
 * Use the Stdout exporter for non-error spans.
 *
 * ## HTTP Attribute Conventions
 *
 * The following span attributes are mapped to Sentry's request/response structures:
 *
 * Request attributes:
 * - `http.url` - Full request URL
 * - `http.method` - HTTP method (GET, POST, etc.)
 * - `http.query` - Query string
 *
 * Response attributes:
 * - `http.response.status_code` - HTTP response status code
 *
 * ## Attribute Classification
 *
 * By default, all unhandled attributes go to `context` (recommended by Sentry).
 * Use the `$classifier` callback to control where attributes are placed:
 *
 * ```php
 * $exporter = new Sentry($dsn, classifier: function(string $key): SentryField {
 *     return match(true) {
 *         str_starts_with($key, 'tenant.') => SentryField::Tag,
 *         default => SentryField::Context,
 *     };
 * });
 * ```
 */
class Sentry implements Exporter
{
    private static ?string $sdkVersion = null;

    private readonly string $endpoint;
    private readonly string $publicKey;
    private readonly string $projectId;

    /** @var Closure(string): SentryField */
    private readonly Closure $classifier;

    /**
     * Create a new Sentry exporter.
     *
     * @param string $dsn Sentry DSN (e.g., https://key@sentry.io/123)
     * @param string|null $environment Optional environment name (e.g., 'production')
     * @param string|null $release Optional release/version identifier (e.g., commit hash)
     * @param string|null $serverName Optional server name/identifier
     * @param Closure(string): SentryField|null $classifier Optional callback to classify attributes
     */
    public function __construct(
        private readonly string $dsn,
        private readonly ?string $environment = null,
        private readonly ?string $release = null,
        private readonly ?string $serverName = null,
        ?Closure $classifier = null,
    ) {
        $this->classifier = $classifier ?? static fn (string $key): SentryField => SentryField::Context;
        $parsed = parse_url($dsn);

        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid Sentry DSN');
        }

        $this->publicKey = $parsed['user'] ?? '';
        $this->projectId = ltrim($parsed['path'] ?? '', '/');

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        $this->endpoint = "{$scheme}://{$host}{$port}/api/{$this->projectId}/envelope/";
    }

    public function export(Span $span): void
    {
        $envelope = $this->buildEnvelope($span);

        if ($envelope === null) {
            return;
        }

        $ch = curl_init($this->endpoint);

        if ($ch === false) {
            error_log('Sentry exporter: Failed to initialize curl');
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-sentry-envelope',
                "X-Sentry-Auth: Sentry sentry_version=7, sentry_key={$this->publicKey}",
            ],
            CURLOPT_TIMEOUT_MS => 1000,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
        ]);

        $result = curl_exec($ch);

        if ($result === false) {
            error_log('Sentry exporter: ' . curl_error($ch));
        } else {
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($statusCode >= 400) {
                error_log("Sentry exporter: HTTP {$statusCode} - {$result}");
            }
        }

        curl_close($ch);
    }

    private function buildEnvelope(Span $span): ?string
    {
        $error = $span->getError();

        if (!$error instanceof \Throwable) {
            return null;
        }

        $attributes = $span->getAttributes();

        $traceId = (string) ($attributes['span.trace_id'] ?? '');
        $spanId = (string) ($attributes['span.id'] ?? '');
        $parentId = $attributes['span.parent_id'] ?? null;
        $startedAt = (float) ($attributes['span.started_at'] ?? microtime(true));
        $finishedAt = (float) ($attributes['span.finished_at'] ?? microtime(true));
        $action = $span->getAction();

        $traceContext = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
        ];

        if (\is_string($parentId)) {
            $traceContext['parent_span_id'] = $parentId;
        }

        $header = json_encode([
            'event_id' => str_replace('-', '', $traceId),
            'sent_at' => date('c'),
            'dsn' => $this->dsn,
        ]);

        $itemHeader = json_encode([
            'type' => 'event',
            'content_type' => 'application/json',
        ]);

        $frames = [];
        foreach (array_reverse($error->getTrace()) as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }
            $sentryFrame = [
                'filename' => $frame['file'],
                'lineno' => $frame['line'] ?? 0,
                'in_app' => !str_contains($frame['file'], '/vendor/'),
            ];
            $sentryFrame['function'] = isset($frame['class'])
                ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function']
                : $frame['function'];
            $frames[] = $sentryFrame;
        }

        $frames[] = [
            'filename' => $error->getFile(),
            'lineno' => $error->getLine(),
            'in_app' => !str_contains($error->getFile(), '/vendor/'),
        ];

        $contexts = [
            'trace' => $traceContext,
            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
        ];

        $request = $this->buildRequest($attributes);
        $response = $this->buildResponse($attributes);

        if ($response !== []) {
            $contexts['response'] = $response;
        }

        [$tags, $customContexts, $extra] = $this->classifyAttributes($attributes);

        if ($customContexts !== []) {
            $contexts['custom'] = $customContexts;
        }

        $payloadData = [
            'level' => 'error',
            'platform' => 'php',
            'sdk' => [
                'name' => 'utopia-php/span',
                'version' => self::$sdkVersion ??= InstalledVersions::getVersion('utopia-php/span') ?? 'unknown',
            ],
            'start_timestamp' => $startedAt,
            'timestamp' => $finishedAt,
            'transaction' => $action,
            'message' => $error->getMessage(),
            'contexts' => $contexts,
            'exception' => [
                'values' => [[
                    'type' => $error::class,
                    'value' => $error->getMessage(),
                    'stacktrace' => ['frames' => $frames],
                ]],
            ],
        ];

        if ($tags !== []) {
            $payloadData['tags'] = $tags;
        }

        if ($extra !== []) {
            $payloadData['extra'] = $extra;
        }

        if ($request !== []) {
            $payloadData['request'] = $request;
        }

        if ($this->environment !== null) {
            $payloadData['environment'] = $this->environment;
        }

        if ($this->release !== null) {
            $payloadData['release'] = $this->release;
        }

        if ($this->serverName !== null) {
            $payloadData['server_name'] = $this->serverName;
        }

        $payload = json_encode($payloadData);

        if ($header === false || $itemHeader === false || $payload === false) {
            return null;
        }

        return "{$header}\n{$itemHeader}\n{$payload}";
    }

    /**
     * Classify attributes into tags, contexts, and extra based on the classifier.
     *
     * @param array<string, mixed> $attributes
     * @return array{array<string, string>, array<string, mixed>, array<string, mixed>}
     */
    private function classifyAttributes(array $attributes): array
    {
        $tags = [];
        $contexts = [];
        $extra = [];

        foreach ($attributes as $key => $value) {
            // Skip internal span attributes
            if (str_starts_with($key, 'span.')) {
                continue;
            }

            // Skip HTTP attributes (handled separately)
            if (str_starts_with($key, 'http.')) {
                continue;
            }

            $field = ($this->classifier)($key);

            switch ($field) {
                case SentryField::Tag:
                    // Tags must be strings, max 200 chars
                    if (\is_scalar($value) || $value === null) {
                        $tags[$key] = substr((string) $value, 0, 200);
                    }
                    break;
                case SentryField::Context:
                    $contexts[$key] = $value;
                    break;
                case SentryField::Extra:
                    $extra[$key] = $value;
                    break;
            }
        }

        return [$tags, $contexts, $extra];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildRequest(array $attributes): array
    {
        $request = [];

        if (isset($attributes['http.url']) && \is_string($attributes['http.url'])) {
            $request['url'] = $attributes['http.url'];
        }

        if (isset($attributes['http.method']) && \is_string($attributes['http.method'])) {
            $request['method'] = $attributes['http.method'];
        }

        if (isset($attributes['http.query']) && \is_string($attributes['http.query'])) {
            $request['query_string'] = $attributes['http.query'];
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildResponse(array $attributes): array
    {
        $response = [];

        if (isset($attributes['http.response.status_code']) && \is_int($attributes['http.response.status_code'])) {
            $response['status_code'] = $attributes['http.response.status_code'];
        }

        return $response;
    }
}
