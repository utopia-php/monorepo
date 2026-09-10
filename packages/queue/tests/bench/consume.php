<?php

/**
 * Consume-side benchmark: what the broker costs a worker, across workload shapes.
 *
 * Runs Broker\Redis and Broker\Nats over the same backlog through the same loop shape
 * Adapter\Swoole runs -- one receive loop per process, a slot channel capped at
 * --coroutines, the commit inside the per-message coroutine.
 *
 * Two concurrency axes, because they are not interchangeable:
 *
 *   --processes    separate consumer processes, each with its own connections. This is
 *                  what replicas buy. Spawned rather than forked: forking a process
 *                  with an initialised Swoole runtime is not something to rely on for a
 *                  measurement.
 *   --coroutines   handler coroutines inside one process, sharing its connections. This
 *                  is what _APP_WORKER_MAX_COROUTINES sets on a deployed worker.
 *
 * And two workload knobs, because which axis helps depends entirely on them:
 *
 *   --sleep-ms     a handler that waits. Coroutine::sleep yields, so sibling handlers
 *                  and the receive loop keep running: coroutines absorb this.
 *   --cpu-iters    a handler that computes (sha256 rounds). PHP runs one coroutine at a
 *                  time, so this yields nothing and coroutines cannot absorb it --
 *                  only processes can.
 *
 * A handler that is purely one or the other is the interesting case at both ends; the
 * mixed shape is what most real jobs are. See tests/bench/run.sh for the sweep.
 *
 * Needs live Redis and NATS, so it is driven by run.sh rather than being run directly:
 *
 *   REDIS_HOST=127.0.0.1 REDIS_PORT=16379 NATS_URL=nats://127.0.0.1:14225 \
 *     php tests/bench/consume.php --backend=nats --processes=4 --coroutines=8
 *
 * Absolute rates are host-bound and mean nothing across machines; compare cells within
 * one run. Drain moves double digits between identical runs, so --repeat and read the
 * median.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\Queue\Broker\Nats as NatsBroker;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Queue;

const DEFAULTS = [
    'backend' => 'both',
    'processes' => '1',
    'coroutines' => '1',
    'messages' => '600',
    'payload' => '512',
    'repeat' => '3',
    'sleep-ms' => '0',
    'cpu-iters' => '0',
    // Milliseconds between child starts. Every consumer provisions on its first
    // receive(), and starting several at once turns that into a storm: measured at four
    // processes against an already-warm R1 stream, a child intermittently spent ~124s
    // inside its first receive() -- the provisioning retry ladder in Broker\Nats::ensure()
    // -- while its siblings drained in 1.3s. Spacing the starts sidesteps it. Zero
    // reproduces the storm, which is what benchmarks/nats-provisioning.php is for.
    'stagger' => '0',
    'label' => '',
    // Internal, set on the children this script spawns.
    'role' => '',
    'share' => '0',
];

$args = DEFAULTS;

// $_SERVER['argv'], not $argv: the latter needs register_argc_argv, which a CLI ini is
// not obliged to set -- and PHPStan is right to refuse to assume it.
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', (string) $arg, $m) === 1 && array_key_exists($m[1], DEFAULTS)) {
        $args[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "unknown option {$arg}\n");
    exit(2);
}

function broker(string $name): RedisBroker|NatsBroker
{
    if ($name === 'redis') {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 16379);

        // The shape cloud wires: a blocking receive connection plus a Locking commands
        // connection. Broker\Nats resolves the same split internally from the factory.
        return new RedisBroker(
            receive: new RedisConnection($host, $port),
            commands: new Locking(new RedisConnection($host, $port)),
        );
    }

    $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';

    return new NatsBroker(fn(): NatsConnection => NatsConnection::connect($url));
}

function queueFor(string $name): Queue
{
    return new Queue('bench_' . $name, 'bench');
}

/**
 * The handler. Sleep yields and computation does not, which is the whole point of
 * having both: the same drain rate responds to a different axis depending on which of
 * these dominates.
 */
function work(float $sleepSeconds, int $cpuIters): string
{
    if ($sleepSeconds > 0) {
        Coroutine::sleep($sleepSeconds);
    }

    // Each round hashes the previous digest, so the chain is dependent: nothing can
    // reorder or elide it, and it is the digest that is returned rather than discarded.
    $sink = '';
    for ($i = 0; $i < $cpuIters; $i++) {
        $sink = hash('sha256', $sink . $i);
    }

    return $sink;
}

/** Nearest-rank percentile over a microsecond sample, in milliseconds. */
function pct(array $samples, float $q): float
{
    if ($samples === []) {
        return 0.0;
    }
    sort($samples);
    $rank = (int) ceil($q * count($samples)) - 1;

    return $samples[max(0, min($rank, count($samples) - 1))] / 1000;
}

/**
 * One consumer process: drain up to its share, then report.
 *
 * @return array{received: int, wall: float, commits: list<float>, error: ?string}
 */
function consume(array $args): array
{
    $client = broker($args['backend']);
    $queue = queueFor($args['backend']);

    $slots = max(1, (int) $args['coroutines']);
    $share = (int) $args['share'];
    $sleep = ((float) $args['sleep-ms']) / 1000;
    $iters = (int) $args['cpu-iters'];

    $out = ['received' => 0, 'wall' => 0.0, 'commits' => [], 'error' => null];

    Coroutine\run(function () use ($client, $queue, $slots, $share, $sleep, $iters, &$out): void {
        $channel = new Channel($slots);
        $group = new WaitGroup();
        $commits = [];
        $received = 0;
        $idle = 0;
        $idleElapsed = 0.0;
        $error = null;

        $started = microtime(true);

        while ($received < $share && $idle < 3) {
            // Reserved before the receive, as the adapter does: a message taken with no
            // slot to run it would sit captive in this loop instead of in the broker.
            $channel->push(true);

            $t0 = hrtime(true);
            try {
                $message = $client->receive($queue, 1);
            } catch (Throwable $e) {
                $error ??= 'receive: ' . $e->getMessage();
                $channel->pop();
                break;
            }
            $t1 = hrtime(true);

            if (!$message instanceof \Utopia\Queue\Message) {
                $channel->pop();
                $idle++;
                $idleElapsed += ($t1 - $t0) / 1_000_000_000;
                continue;
            }

            $idle = 0;
            $received++;

            $group->add();
            Coroutine::create(function () use ($client, $queue, $message, $channel, $group, $sleep, $iters, &$commits, &$error): void {
                // Slot and wait group released in finally: skipping either on a throw
                // blocks the receive loop on a slot that never comes back and leaves
                // wait() hanging, so a failed run would present as a hung one.
                try {
                    work($sleep, $iters);

                    // Clock starts after the handler, so this is the acknowledgment
                    // round trip and not the workload.
                    $c0 = hrtime(true);
                    $client->commit($queue, $message);
                    $commits[] = (hrtime(true) - $c0) / 1000;
                } catch (Throwable $e) {
                    $error ??= 'commit: ' . $e->getMessage();
                } finally {
                    $channel->pop();
                    $group->done();
                }
            });
        }

        // Outstanding acknowledgments have to land before the clock stops, or the rate
        // counts messages this process had not finished acknowledging.
        $group->wait();

        // Idle receives are the stop condition, not work; leaving them in understates
        // the rate.
        $out = [
            'received' => $received,
            'wall' => max(0.0, microtime(true) - $started - $idleElapsed),
            'commits' => $commits,
            'error' => $error,
        ];
    });

    $client->close();

    return $out;
}

/**
 * Publish the backlog, spawn the consumer processes, and aggregate what they report.
 *
 * Every child is started before any is read, so they drain concurrently rather than in
 * sequence. Drain is total received over the longest child's wall clock: the run is not
 * finished until the slowest one is.
 *
 * @return array{drain: float, p50: float, p95: float, received: int, error: ?string}
 */
function measure(string $name, array $args): array
{
    $client = broker($name);
    $queue = queueFor($name);
    $total = (int) $args['messages'];
    $processes = max(1, (int) $args['processes']);
    $stagger = max(0, (int) $args['stagger']);
    $filler = str_repeat('x', (int) $args['payload']);

    // Provision, and drain the provisioning message, before the clock matters.
    $client->publish($queue, ['warmup' => true, 'filler' => $filler]);
    $warm = $client->receive($queue, 5);
    if ($warm instanceof \Utopia\Queue\Message) {
        $client->commit($queue, $warm);
    }

    for ($i = 0; $i < $total; $i++) {
        $client->publish($queue, ['n' => $i, 'filler' => $filler]);
    }
    $client->close();

    $share = (int) ceil($total / $processes);
    $handles = [];

    for ($p = 0; $p < $processes; $p++) {
        $command = [PHP_BINARY, __FILE__, '--role=consume', '--backend=' . $name, '--share=' . $share];
        foreach (['coroutines', 'messages', 'payload', 'sleep-ms', 'cpu-iters'] as $key) {  // not stagger: parent-only
            $command[] = '--' . $key . '=' . $args[$key];
        }

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            return ['drain' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'received' => 0, 'error' => 'spawn failed'];
        }

        $handles[] = ['process' => $process, 'stdout' => $pipes[1]];

        if ($stagger > 0 && $p < $processes - 1) {
            usleep($stagger * 1000);
        }
    }

    $received = 0;
    $walls = [];
    $commits = [];
    $error = null;

    foreach ($handles as $handle) {
        $raw = stream_get_contents($handle['stdout']);
        fclose($handle['stdout']);
        proc_close($handle['process']);

        $child = json_decode((string) $raw, true);
        if (!is_array($child)) {
            $error ??= 'child returned no result';
            continue;
        }

        if (getenv('BENCH_DEBUG')) {
            fwrite(STDERR, sprintf(
                "    child: received=%d wall=%.3fs error=%s\n",
                (int) $child['received'],
                (float) $child['wall'],
                $child['error'] ?? '-',
            ));
        }

        $received += (int) $child['received'];
        $walls[] = (float) $child['wall'];
        $commits = array_merge($commits, array_map(floatval(...), $child['commits']));
        $error ??= $child['error'];
    }

    // Drain is total received over the longest child's clock: the run is not finished
    // until the slowest one is.
    $wall = $walls === [] ? 0.0 : max($walls);

    // A child whose clock dwarfs its siblings' did not drain slowly, it stalled -- and
    // averaging that into a median reports a broker three orders of magnitude slower
    // than it is. Rejected as a sample rather than published as a number.
    //
    // Compared against the fastest child rather than the median: when two of four
    // stall, the median is itself a stalled value and a median-based test waves the
    // run through. Children share one queue and an equal share, so in a healthy run
    // every clock is within a hair of every other. The absolute floor keeps ordinary
    // spread on a fast run from tripping it.
    $working = array_values(array_filter($walls, static fn(float $w): bool => $w > 0.0));
    sort($working);
    $fastest = $working === [] ? 0.0 : $working[0];
    if ($fastest > 0.0 && $wall > max($fastest * 3, $fastest + 5.0)) {
        $error ??= sprintf('child stalled: slowest %.2fs against fastest %.2fs', $wall, $fastest);
        $received = 0;
    }

    return [
        'drain' => $wall > 0 ? $received / $wall : 0.0,
        'p50' => pct($commits, 0.50),
        'p95' => pct($commits, 0.95),
        'received' => $received,
        'error' => $error,
    ];
}

// Child: drain and hand the samples back as JSON on stdout.
if ($args['role'] === 'consume') {
    echo json_encode(consume($args));
    exit(0);
}

$backends = $args['backend'] === 'both' ? ['redis', 'nats'] : [$args['backend']];
$repeat = max(1, (int) $args['repeat']);
$total = (int) $args['messages'];

printf(
    "%s%d messages, %dB payload, %s x %s (processes x coroutines), sleep=%sms cpu=%s iters, median of %d\n\n",
    $args['label'] === '' ? '' : $args['label'] . ': ',
    $total,
    (int) $args['payload'],
    $args['processes'],
    $args['coroutines'],
    $args['sleep-ms'],
    $args['cpu-iters'],
    $repeat,
);
printf("%-7s %12s %11s %11s\n", 'backend', 'drain msg/s', 'ack p50', 'ack p95');
printf("%-7s %12s %11s %11s\n", '-------', '------------', '-----------', '-----------');

foreach ($backends as $name) {
    // Only runs that drained everything are eligible for the median: a partial run
    // reports a rate over a fraction of the workload, and averaging that in reads as a
    // slower broker rather than as an aborted sample.
    $complete = [];
    $dropped = [];
    $errors = [];

    for ($r = 0; $r < $repeat; $r++) {
        $sample = measure($name, $args);

        if ($sample['error'] !== null) {
            $errors[] = $sample['error'];
        }

        if ($sample['received'] < $total) {
            $dropped[] = $sample['received'];

            continue;
        }

        $complete[] = $sample;
    }

    $note = '';
    if ($dropped !== []) {
        $note .= sprintf('   (!! %d/%d incomplete: %s of %d)', count($dropped), $repeat, implode(', ', $dropped), $total);
    }
    if ($errors !== []) {
        $note .= '   (!! ' . $errors[0] . ')';
    }

    if ($complete === []) {
        printf("%-7s %12s %11s %11s%s\n", $name, 'n/a', 'n/a', 'n/a', $note);

        continue;
    }

    $pick = static function (string $key) use ($complete): float {
        $series = array_map(static fn(array $s): float => $s[$key], $complete);
        sort($series);

        return $series[intdiv(count($series), 2)];
    };

    printf(
        "%-7s %12.0f %9.2fms %9.2fms%s\n",
        $name,
        $pick('drain'),
        $pick('p50'),
        $pick('p95'),
        $note,
    );
}
