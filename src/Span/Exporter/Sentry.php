<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Exports error spans to Sentry Issues.
 *
 * Only spans with errors are sent. Non-error spans are silently skipped.
 * Use the Stdout exporter for non-error spans.
 */
readonly class Sentry implements Exporter
{
    private string $endpoint;
    private string $publicKey;
    private string $projectId;

    /**
     * Create a new Sentry exporter.
     *
     * @param string $dsn Sentry DSN (e.g., https://key@sentry.io/123)
     * @param string|null $environment Optional environment name (e.g., 'production')
     * @param string|null $release Optional release/version identifier (e.g., commit hash)
     * @param string|null $serverName Optional server name/identifier
     */
    public function __construct(
        private string $dsn,
        private ?string $environment = null,
        private ?string $release = null,
        private ?string $serverName = null
    ) {
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

        $payloadData = [
            'level' => 'error',
            'platform' => 'php',
            'sdk' => ['name' => 'utopia-php/span'],
            'start_timestamp' => $startedAt,
            'timestamp' => $finishedAt,
            'transaction' => $action,
            'message' => $error->getMessage(),
            'contexts' => [
                'trace' => $traceContext,
                'runtime' => [
                    'name' => 'php',
                    'version' => PHP_VERSION,
                    'sapi' => PHP_SAPI,
                ],
            ],
            'exception' => [
                'values' => [[
                    'type' => $error::class,
                    'value' => $error->getMessage(),
                    'stacktrace' => ['frames' => $frames],
                ]],
            ],
            'extra' => $attributes,
        ];

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
}
