<?php

/**
 * DNS server benchmark: measures throughput and latency distribution
 * under concurrent load.
 *
 * Usage:
 *   php tests/benchmark.php [--server=127.0.0.1] [--port=5300]
 *       [--iterations=10000] [--concurrency=10]
 *       [--domains=dev.appwrite.io,dev2.appwrite.io] [--types=A,AAAA,CNAME,TXT,NS]
 *
 * Forks --concurrency worker processes, each with its own UDP client,
 * splitting --iterations queries per (domain, type) pair between them.
 * Reports requests per second, min/max/avg, and p50-p99 latency.
 * Exits non-zero when any query fails.
 */

require __DIR__ . '/../vendor/autoload.php';

use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

$options = getopt('', ['server::', 'port::', 'iterations::', 'concurrency::', 'domains::', 'types::']);

$server = (string) ($options['server'] ?? '127.0.0.1');
$port = (int) ($options['port'] ?? 5300);
$iterations = max(1, (int) ($options['iterations'] ?? 10000));
$concurrency = max(1, (int) ($options['concurrency'] ?? 10));
$domains = array_filter(explode(',', (string) ($options['domains'] ?? 'dev.appwrite.io,dev2.appwrite.io')));
$types = array_filter(explode(',', (string) ($options['types'] ?? 'A,AAAA,CNAME,TXT,NS')));

$cases = [];
foreach ($domains as $domain) {
    foreach ($types as $type) {
        $typeCode = Record::typeNameToCode($type);
        if ($typeCode === null) {
            fwrite(STDERR, "Unsupported record type: $type\n");
            exit(1);
        }
        $cases[] = [trim($domain), $typeCode, $type];
    }
}

$total = $iterations * count($cases);
echo "Benchmarking $server:$port — $iterations queries per record across " . count($cases) . " cases, concurrency $concurrency ($total total)\n";

$tmpDir = sys_get_temp_dir() . '/dns_benchmark_' . uniqid();
if (!mkdir($tmpDir, 0777, true)) {
    fwrite(STDERR, "Failed to create temporary directory: $tmpDir\n");
    exit(1);
}

$startTime = microtime(true);
$pids = [];

for ($worker = 0; $worker < $concurrency; $worker++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "Failed to fork worker $worker\n");
        exit(1);
    }

    if ($pid > 0) {
        $pids[] = $pid;
        continue;
    }

    // Worker: run this worker's share of every case with one persistent client
    $times = [];
    $errors = [];
    $share = intdiv($iterations, $concurrency) + ($worker < $iterations % $concurrency ? 1 : 0);

    try {
        $client = new Client($server, $port);

        foreach ($cases as [$domain, $typeCode, $typeName]) {
            for ($i = 0; $i < $share; $i++) {
                $begin = hrtime(true);
                try {
                    $client->query(Message::query(new Question($domain, $typeCode)));
                    $times[] = (hrtime(true) - $begin) / 1e6;
                } catch (Throwable $e) {
                    $errors[] = count($errors) < 5 ? "$domain $typeName: {$e->getMessage()}" : null;
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    file_put_contents("$tmpDir/worker_$worker.json", json_encode([
        'times' => $times,
        'errors' => count($errors),
        'messages' => array_values(array_filter($errors)),
    ]));
    exit(0);
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$totalTime = microtime(true) - $startTime;
$times = [];
$errorCount = 0;
$errorMessages = [];

for ($worker = 0; $worker < $concurrency; $worker++) {
    $content = @file_get_contents("$tmpDir/worker_$worker.json");
    $result = $content !== false ? json_decode($content, true) : null;
    if (!is_array($result)) {
        fwrite(STDERR, "Missing result from worker $worker\n");
        $errorCount++;
        continue;
    }
    $times = array_merge($times, $result['times']);
    $errorCount += $result['errors'];
    $errorMessages = array_merge($errorMessages, $result['messages']);
    @unlink("$tmpDir/worker_$worker.json");
}
@rmdir($tmpDir);

function percentile(array $sorted, float $p): float
{
    return $sorted[(int) ceil($p * count($sorted)) - 1];
}

echo "\n--- Benchmark Results ---\n";
echo 'Successful: ' . count($times) . " / $total\n";
echo "Failed: $errorCount\n";
echo 'Total Test Time: ' . round($totalTime, 2) . " seconds\n";
echo 'Requests Per Second: ' . round(count($times) / max($totalTime, 1e-9), 2) . " req/s\n";

if ($times !== []) {
    sort($times);
    echo "\n--- Latency ---\n";
    echo 'Min: ' . round($times[0], 2) . " ms\n";
    echo 'Max: ' . round($times[count($times) - 1], 2) . " ms\n";
    echo 'Avg: ' . round(array_sum($times) / count($times), 2) . " ms\n";
    foreach ([0.50, 0.75, 0.90, 0.95, 0.99] as $p) {
        echo 'p' . (int) ($p * 100) . ': ' . round(percentile($times, $p), 2) . " ms\n";
    }
}

foreach ($errorMessages as $message) {
    fwrite(STDERR, "error: $message\n");
}

exit($errorCount > 0 ? 1 : 0);
