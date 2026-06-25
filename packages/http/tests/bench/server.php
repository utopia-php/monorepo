<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Swoole\Constant;
use Utopia\Http\Adapter\Swoole\Mode;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\System\System;

/**
 * Benchmark server: MODE=defaults|a|b selects the Swoole configuration.
 *
 * By default each mode runs with its real shipped settings, so a run
 * answers "which mode for this workload?". Set PIN_WORKERS=1 to instead
 * pin worker_num to 1x cores across all modes, isolating dispatch
 * strategy from process count (HYPERLOOP_A normally claims 6x cores).
 */

// SLEEP_MS parked/blocked + CPU_ITERS rounds of sha256, approximating a
// request that waits on a downstream service and then renders. Read with
// an explicit false check so SLEEP_MS=0 / CPU_ITERS=0 aren't swallowed by
// "0" being falsy.
$sleepEnv = getenv('SLEEP_MS');
$itersEnv = getenv('CPU_ITERS');
$sleep = $sleepEnv === false ? 50 : (int) $sleepEnv;
$iterations = $itersEnv === false ? 1_000 : (int) $itersEnv;

Http::get('/work')
    ->inject('response')
    ->action(function (Response $response) use ($sleep, $iterations) {
        // Native blocking sleep on purpose: it models real I/O (PDO, file
        // reads, streams). In HYPERLOOP_B the hook_flags setting turns this
        // into a coroutine yield; in HYPERLOOP_A it blocks the worker.
        if ($sleep > 0) {
            usleep($sleep * 1_000);
        }

        $payload = str_repeat('x', 1024);
        for ($i = 0; $i < $iterations; $i++) {
            $payload = hash('sha256', $payload);
        }

        $response->send($payload);
    });

$settings = match (getenv('MODE') ?: 'b') {
    'defaults' => [],
    'a' => Mode::HYPERLOOP_A->settings(),
    default => Mode::HYPERLOOP_B->settings(),
};

if (getenv('PIN_WORKERS')) {
    $settings[Constant::OPTION_WORKER_NUM] = (int) max(1, ceil(System::getCPU()));
}

$server = new Server('0.0.0.0', '80', $settings);
$http = new Http($server, 'UTC');

$http->start();
