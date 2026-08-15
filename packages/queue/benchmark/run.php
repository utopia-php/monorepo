<?php

declare(strict_types=1);

/**
 * Current-branch queue consume benchmark.
 *
 * Scenarios:
 * - single: one queue, N messages, concurrency C
 * - multi-fair: Q queues, N/Q each, concurrency C/Q each (same total slots as single)
 * - multi-isolated: Q queues, N/Q each, concurrency C each (independent caps)
 *
 * Each scenario also runs repeated drain cycles and fails if retained memory
 * grows beyond BENCH_MEMORY_LIMIT_BYTES (default 2 MiB) after GC.
 *
 * Env:
 *   BENCH_MESSAGES=2000
 *   BENCH_CONCURRENCY=8
 *   BENCH_QUEUES=4
 *   BENCH_ROUNDS=12
 *   BENCH_WARMUP=2
 *   BENCH_MEMORY_LIMIT_BYTES=2097152
 *   BENCH_REPORT=/path/to/append.md
 *   BENCH_JSON=/path/to/results.json
 */

use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Benchmark\InMemoryConnection;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/InMemoryConnection.php';

if (!\extension_loaded('swoole')) {
    fwrite(STDERR, "ext-swoole is required for the queue benchmark\n");
    exit(1);
}

$messages = max(1, (int) (getenv('BENCH_MESSAGES') ?: 2000));
$concurrency = max(1, (int) (getenv('BENCH_CONCURRENCY') ?: 8));
$queueCount = max(2, (int) (getenv('BENCH_QUEUES') ?: 4));
$rounds = max(4, (int) (getenv('BENCH_ROUNDS') ?: 12));
$warmup = max(0, (int) (getenv('BENCH_WARMUP') ?: 2));
$memoryLimit = max(0, (int) (getenv('BENCH_MEMORY_LIMIT_BYTES') ?: 2 * 1024 * 1024));
$namespace = 'bench';

/**
 * @param list<array{name: string, maxCoroutines: int, count: int}> $queues
 * @return array{seconds: float, messages: int, peak_bytes: int, processed: int}
 */
$runOnce = static function (array $queues) use ($namespace): array {
    $connection = new InMemoryConnection();
    $broker = new Redis($connection, $connection);
    $total = 0;
    $queueObjects = [];

    foreach ($queues as $spec) {
        $queue = new Queue($spec['name'], $namespace);
        $queueObjects[$spec['name']] = $queue;
        for ($i = 0; $i < $spec['count']; $i++) {
            $broker->enqueue($queue, ['n' => $i, 'q' => $spec['name']]);
        }
        $total += $spec['count'];
    }

    $processed = 0;
    $peak = memory_get_usage(true);
    $started = hrtime(true);

    \Swoole\Coroutine\run(function () use ($broker, $queues, $queueObjects, $namespace, $total, &$processed, &$peak): void {
        $adapter = new Swoole($broker, 1, $namespace);
        $server = new Server($adapter);

        $specs = [];
        foreach ($queues as $spec) {
            $server->job($spec['name'], $spec['maxCoroutines']);
            $specs[] = [
                'queue' => $queueObjects[$spec['name']],
                'maxCoroutines' => $server->coroutines($spec['name']),
                'consumer' => $broker,
            ];
        }

        if (\count($server->jobs()) !== \count($queues)) {
            throw new RuntimeException('job() registration count mismatch');
        }

        $adapter->consume(
            static function () use (&$processed, &$peak, $adapter, $total): void {
                hash('xxh3', (string) $processed);
                $peak = max($peak, memory_get_usage(true));
                if (++$processed >= $total) {
                    $adapter->stop();
                }
            },
            static fn(): null => null,
            static fn(): null => null,
            $specs,
        );
    });

    $seconds = (hrtime(true) - $started) / 1e9;

    if ($processed !== $total) {
        throw new RuntimeException("expected {$total} messages, processed {$processed}");
    }

    return [
        'seconds' => $seconds,
        'messages' => $total,
        'peak_bytes' => $peak,
        'processed' => $processed,
    ];
};

/**
 * @param callable(): array{seconds: float, messages: int, peak_bytes: int, processed: int} $once
 * @return array{
 *   label: string,
 *   messages: int,
 *   messages_per_second: float,
 *   avg_seconds: float,
 *   peak_bytes: int,
 *   memory_start_bytes: int,
 *   memory_end_bytes: int,
 *   memory_growth_bytes: int,
 *   leak: bool
 * }
 */
$measure = static function (string $label, callable $once) use ($rounds, $warmup, $memoryLimit): array {
    for ($i = 0; $i < $warmup; $i++) {
        $once();
        gc_collect_cycles();
    }

    $samples = [];
    $peak = 0;
    $memorySamples = [];
    $messageCount = 0;

    for ($i = 0; $i < $rounds; $i++) {
        $result = $once();
        $messageCount = $result['messages'];
        $samples[] = $result['seconds'];
        $peak = max($peak, $result['peak_bytes']);
        gc_collect_cycles();
        $memorySamples[] = memory_get_usage(true);
    }

    $half = max(1, (int) floor(\count($memorySamples) / 2));
    $startWindow = array_slice($memorySamples, 0, $half);
    $endWindow = array_slice($memorySamples, -$half);
    $memoryStart = (int) round(array_sum($startWindow) / \count($startWindow));
    $memoryEnd = (int) round(array_sum($endWindow) / \count($endWindow));
    $growth = $memoryEnd - $memoryStart;
    $avgSeconds = array_sum($samples) / \count($samples);

    return [
        'label' => $label,
        'messages' => $messageCount,
        'messages_per_second' => $messageCount / max($avgSeconds, 1e-9),
        'avg_seconds' => $avgSeconds,
        'peak_bytes' => $peak,
        'memory_start_bytes' => $memoryStart,
        'memory_end_bytes' => $memoryEnd,
        'memory_growth_bytes' => $growth,
        'leak' => $growth > $memoryLimit,
    ];
};

$perQueue = intdiv($messages, $queueCount);
$fairConcurrency = max(1, intdiv($concurrency, $queueCount));

$singleQueues = [
    ['name' => 'bench-single', 'maxCoroutines' => $concurrency, 'count' => $messages],
];

$multiFairQueues = [];
$multiIsolatedQueues = [];
for ($q = 0; $q < $queueCount; $q++) {
    $name = 'bench-q' . $q;
    $count = $q === $queueCount - 1
        ? $messages - ($perQueue * ($queueCount - 1))
        : $perQueue;
    $multiFairQueues[] = ['name' => $name, 'maxCoroutines' => $fairConcurrency, 'count' => $count];
    $multiIsolatedQueues[] = ['name' => $name, 'maxCoroutines' => $concurrency, 'count' => $count];
}

$results = [
    $measure('current / single', static fn(): array => $runOnce($singleQueues)),
    $measure('current / multi-fair', static fn(): array => $runOnce($multiFairQueues)),
    $measure('current / multi-isolated', static fn(): array => $runOnce($multiIsolatedQueues)),
];

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
    . "| --- | ---: | ---: | ---: | ---: | --- |\n";

$failed = false;
foreach ($results as $row) {
    $report .= sprintf(
        "| %s | %s | %.3f | %s | %s | %s |\n",
        $row['label'],
        $fmtInt($row['messages_per_second']),
        $row['avg_seconds'],
        $fmtBytes($row['peak_bytes']),
        $fmtBytes($row['memory_growth_bytes']),
        $row['leak'] ? 'yes' : 'no',
    );
    if ($row['leak']) {
        $failed = true;
    }
}

$report .= "\n"
    . "- Messages: {$messages}, concurrency: {$concurrency}, queues: {$queueCount}, "
    . "rounds: {$rounds} (warmup {$warmup})\n"
    . '- multi-fair uses concurrency/queues per loop (same total slots as single); '
    . "multi-isolated uses full concurrency per loop\n"
    . '- Leak = retained memory after GC grew more than '
    . $fmtBytes($memoryLimit) . " across timed rounds\n";

echo "### queue (current)\n\n{$report}\n";

$reportPath = getenv('BENCH_REPORT');
if (\is_string($reportPath) && $reportPath !== '') {
    file_put_contents($reportPath, "### queue (current)\n\n{$report}\n", FILE_APPEND);
}

$jsonPath = getenv('BENCH_JSON');
if (\is_string($jsonPath) && $jsonPath !== '') {
    file_put_contents($jsonPath, json_encode([
        'ref' => 'current',
        'config' => [
            'messages' => $messages,
            'concurrency' => $concurrency,
            'queueCount' => $queueCount,
            'rounds' => $rounds,
            'warmup' => $warmup,
            'memoryLimit' => $memoryLimit,
        ],
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n");
}

exit($failed ? 1 : 0);
