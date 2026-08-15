<?php

declare(strict_types=1);

/**
 * Baseline (pre-multi-queue) single-queue consume benchmark.
 *
 * Intended to run against origin/main's packages/queue checkout, where:
 * - Swoole($consumer, $workers, $queue, $namespace, maxCoroutines: $c)
 * - consume($msg, $ok, $err) with no $queues argument
 *
 * Same env knobs as run.php (BENCH_MESSAGES, BENCH_CONCURRENCY, …).
 */

use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Benchmark\InMemoryConnection;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/InMemoryConnection.php';

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "ext-swoole is required for the queue benchmark\n");
    exit(1);
}

$messages = max(1, (int) (getenv('BENCH_MESSAGES') ?: 2000));
$concurrency = max(1, (int) (getenv('BENCH_CONCURRENCY') ?: 8));
$rounds = max(4, (int) (getenv('BENCH_ROUNDS') ?: 12));
$warmup = max(0, (int) (getenv('BENCH_WARMUP') ?: 2));
$memoryLimit = max(0, (int) (getenv('BENCH_MEMORY_LIMIT_BYTES') ?: 2 * 1024 * 1024));
$queueName = 'bench-single';
$namespace = 'bench';
$baselineRef = getenv('BENCH_BASELINE_REF') ?: 'origin/main';

/**
 * @return array{seconds: float, messages: int, peak_bytes: int, processed: int}
 */
$runOnce = static function () use ($messages, $concurrency, $queueName, $namespace): array {
    $connection = new InMemoryConnection();
    $broker = new Redis($connection, $connection);
    $queue = new Queue($queueName, $namespace);

    for ($i = 0; $i < $messages; $i++) {
        $broker->enqueue($queue, ['n' => $i]);
    }

    $processed = 0;
    $peak = memory_get_usage(true);
    $adapter = new Swoole($broker, 1, $queueName, $namespace, maxCoroutines: $concurrency);

    $started = hrtime(true);

    \Swoole\Coroutine\run(function () use ($adapter, $messages, &$processed, &$peak): void {
        $adapter->consume(
            static function () use (&$processed, &$peak, $adapter, $messages): void {
                hash('xxh3', (string) $processed);
                $peak = max($peak, memory_get_usage(true));
                if (++$processed >= $messages) {
                    $adapter->stop();
                }
            },
            static fn(): null => null,
            static fn(): null => null,
        );
    });

    $seconds = (hrtime(true) - $started) / 1e9;

    if ($processed !== $messages) {
        throw new RuntimeException("expected {$messages} messages, processed {$processed}");
    }

    return [
        'seconds' => $seconds,
        'messages' => $messages,
        'peak_bytes' => $peak,
        'processed' => $processed,
    ];
};

for ($i = 0; $i < $warmup; $i++) {
    $runOnce();
    gc_collect_cycles();
}

$samples = [];
$peak = 0;
$memorySamples = [];

for ($i = 0; $i < $rounds; $i++) {
    $result = $runOnce();
    $samples[] = $result['seconds'];
    $peak = max($peak, $result['peak_bytes']);
    gc_collect_cycles();
    $memorySamples[] = memory_get_usage(true);
}

$half = max(1, (int) floor(count($memorySamples) / 2));
$startWindow = array_slice($memorySamples, 0, $half);
$endWindow = array_slice($memorySamples, -$half);
$memoryStart = (int) round(array_sum($startWindow) / count($startWindow));
$memoryEnd = (int) round(array_sum($endWindow) / count($endWindow));
$growth = $memoryEnd - $memoryStart;
$avgSeconds = array_sum($samples) / count($samples);
$mps = $messages / max($avgSeconds, 1e-9);
$leak = $growth > $memoryLimit;

$fmtInt = static fn(int|float $n): string => number_format((float) $n);
$fmtBytes = static function (int $bytes): string {
    $sign = $bytes < 0 ? '-' : '';
    $bytes = abs($bytes);
    if ($bytes < 1024) {
        return $sign . $bytes . ' B';
    }
    if ($bytes < 1024 ** 2) {
        return $sign . number_format($bytes / 1024, 1) . ' KiB';
    }

    return $sign . number_format($bytes / (1024 ** 2), 2) . ' MiB';
};

$report = "| Scenario | msg/s | avg s | peak RSS | mem growth | leak |\n"
    . "| --- | ---: | ---: | ---: | ---: | --- |\n"
    . sprintf(
        "| baseline / single (%s) | %s | %.3f | %s | %s | %s |\n",
        $baselineRef,
        $fmtInt($mps),
        $avgSeconds,
        $fmtBytes($peak),
        $fmtBytes($growth),
        $leak ? 'yes' : 'no',
    )
    . "\n"
    . "- Messages: {$messages}, concurrency: {$concurrency}, rounds: {$rounds} (warmup {$warmup})\n"
    . '- Leak = retained memory after GC grew more than '
    . $fmtBytes($memoryLimit) . " across timed rounds\n";

echo "### queue (baseline)\n\n{$report}\n";

$reportPath = getenv('BENCH_REPORT');
if (is_string($reportPath) && $reportPath !== '') {
    file_put_contents($reportPath, "### queue (baseline)\n\n{$report}\n", FILE_APPEND);
}

$jsonPath = getenv('BENCH_JSON');
if (is_string($jsonPath) && $jsonPath !== '') {
    file_put_contents($jsonPath, json_encode([
        'ref' => 'baseline',
        'baseline' => $baselineRef,
        'config' => [
            'messages' => $messages,
            'concurrency' => $concurrency,
            'rounds' => $rounds,
            'warmup' => $warmup,
            'memoryLimit' => $memoryLimit,
        ],
        'results' => [[
            'label' => "baseline / single ({$baselineRef})",
            'messages' => $messages,
            'messages_per_second' => $mps,
            'avg_seconds' => $avgSeconds,
            'peak_bytes' => $peak,
            'memory_start_bytes' => $memoryStart,
            'memory_end_bytes' => $memoryEnd,
            'memory_growth_bytes' => $growth,
            'leak' => $leak,
        ]],
    ], JSON_PRETTY_PRINT) . "\n");
}

exit($leak ? 1 : 0);
