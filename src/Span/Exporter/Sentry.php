<?php

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Exports spans to Sentry
 */
class Sentry implements Exporter
{
    private string $dsn;
    private string $endpoint;
    private string $publicKey;
    private string $projectId;
    private ?string $environment;

    public function __construct(string $dsn, ?string $environment = null)
    {
        $this->dsn = $dsn;
        $this->environment = $environment;
        $this->parseDsn($dsn);
    }

    private function parseDsn(string $dsn): void
    {
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
        $attributes = $span->getAttributes();

        $traceId = (string) ($attributes['span.trace_id'] ?? '');
        $spanId = (string) ($attributes['span.id'] ?? '');
        $startedAt = (float) ($attributes['span.started_at'] ?? 0);
        $finishedAt = (float) ($attributes['span.finished_at'] ?? microtime(true));

        $action = $attributes['action'] ?? 'unknown';
        $parentId = $attributes['span.parent_id'] ?? null;

        $traceContext = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'op' => $action,
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
            'type' => 'transaction',
            'content_type' => 'application/json',
        ]);

        $payloadData = [
            'type' => 'transaction',
            'transaction' => $action,
            'start_timestamp' => $startedAt,
            'timestamp' => $finishedAt,
            'contexts' => [
                'trace' => $traceContext,
            ],
            'spans' => [],
            'extra' => $attributes,
        ];

        if ($this->environment !== null) {
            $payloadData['environment'] = $this->environment;
        }

        $payload = json_encode($payloadData);

        if ($header === false || $itemHeader === false || $payload === false) {
            return null;
        }

        return "{$header}\n{$itemHeader}\n{$payload}";
    }
}
