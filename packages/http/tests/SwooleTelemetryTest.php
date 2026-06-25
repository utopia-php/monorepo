<?php

declare(strict_types=1);

namespace Utopia\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Http\Adapter\Swoole\Server;

final class SwooleTelemetryTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function metricNames(): array
    {
        $ref = new ReflectionClass(Server::class);
        $names = [];
        foreach (['WORKER_STATS', 'SERVER_STATS', 'COROUTINE_STATS'] as $map) {
            /** @var array<string, string> $value */
            $value = $ref->getConstant($map);
            $names = [...$names, ...array_values($value)];
        }
        foreach ($ref->getConstants() as $name => $value) {
            if (str_starts_with($name, 'METRIC_')) {
                $names[] = $value;
            }
        }

        return $names;
    }

    public function testEveryMetricNameIsUniqueAndNamespaced(): void
    {
        $names = $this->metricNames();

        $this->assertNotEmpty($names);
        $this->assertSame($names, array_values(array_unique($names)), 'duplicate telemetry metric name');
        foreach ($names as $name) {
            $this->assertStringStartsWith('swoole.', $name);
        }
    }

    public function testWorkerAndServerStatKeysAreDisjoint(): void
    {
        $ref = new ReflectionClass(Server::class);
        /** @var array<string, string> $worker */
        $worker = $ref->getConstant('WORKER_STATS');
        /** @var array<string, string> $server */
        $server = $ref->getConstant('SERVER_STATS');

        // A stats() key is either worker-local or server-wide, never both.
        $this->assertSame([], array_intersect(array_keys($worker), array_keys($server)));
    }
}
