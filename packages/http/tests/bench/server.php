<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Swoole\Constant;
use Swoole\Coroutine;
use Utopia\Http\Adapter\Swoole\Mode;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\System\System;

/**
 * Benchmark server: MODE=defaults|a|b selects the Swoole configuration.
 *
 * worker_num is pinned to 1x cores for every mode so runs compare
 * scheduling strategy at equal resources (HYPERLOOP_A normally claims
 * 6x cores, which would measure process count, not dispatch).
 */

// SLEEP_MS parked/blocked + CPU_ITERS rounds of sha256, approximating a
// request that waits on a downstream service and then renders.
$sleep = (int) (getenv('SLEEP_MS') ?: 50);
$iterations = (int) (getenv('CPU_ITERS') ?: 1_000);

Http::get('/work')
    ->inject('response')
    ->action(function (Response $response) use ($sleep, $iterations) {
        if ($sleep > 0) {
            if (Coroutine::getCid() !== -1) {
                Coroutine::sleep($sleep / 1_000);
            } else {
                usleep($sleep * 1_000);
            }
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

$settings[Constant::OPTION_WORKER_NUM] = (int) max(1, ceil(System::getCPU()));

$server = new Server('0.0.0.0', '80', $settings);
$http = new Http($server, 'UTC');

$http->start();
