<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

/**
 * A field-honouring in-memory adapter that implements the batch load and the
 * ttl-aware save natively, standing in for Redis so the facade's multi-field
 * and ttl passthrough can be exercised on a bare host. The native Redis path
 * (HMGET / HGETALL / EXPIRE) is covered by the Redis E2E suite instead.
 */
final class FakeFieldAdapter implements Adapter
{
    /** @var array<string, array<string, array{time: int, data: mixed}>> */
    public array $store = [];

    /** @var array<string, int> key => armed ttl */
    public array $ttls = [];

    public function load(string $key, int $ttl, string|array $hash = ''): mixed
    {
        if (\is_array($hash)) {
            $now = time();
            $fields = $hash === [] ? array_keys($this->store[$key] ?? []) : $hash;
            $result = [];
            foreach ($fields as $field) {
                $entry = $this->store[$key][$field] ?? null;
                if ($entry !== null && $entry['time'] + $ttl > $now) {
                    $result[$field] = $entry['data'];
                }
            }

            return $result;
        }

        $hash = ($hash === '' || $hash === '0') ? $key : $hash;
        $entry = $this->store[$key][$hash] ?? null;

        return ($entry !== null && $entry['time'] + $ttl > time()) ? $entry['data'] : false;
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        $hash = ($hash === '' || $hash === '0') ? $key : $hash;
        $this->store[$key][$hash] = ['time' => time(), 'data' => $data];

        if ($ttl > 0) {
            $this->ttls[$key] = $ttl;
        }

        return $data;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        return true;
    }

    public function list(string $key): array
    {
        return array_keys($this->store[$key] ?? []);
    }

    public function purge(string $key, string $hash = ''): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        return \count($this->store);
    }

    public function getName(?string $key = null): string
    {
        return 'fake';
    }
}

final class BatchTest extends TestCase
{
    public function testLoadFieldsReturnsOnlyPresentFields(): void
    {
        $cache = new Cache(new FakeFieldAdapter());
        $cache->setCaseSensitivity(true);
        $cache->save('client:1', ['sequence' => 5], 'topicA');
        $cache->save('client:1', ['sequence' => 9], 'topicB');

        $result = $cache->load('client:1', 3600, ['topicA', 'topicB', 'missing']);

        $this->assertSame([
            'topicA' => ['sequence' => 5],
            'topicB' => ['sequence' => 9],
        ], $result);
    }

    public function testEmptyFieldsLoadsAll(): void
    {
        $cache = new Cache(new FakeFieldAdapter());
        $cache->setCaseSensitivity(true);
        $cache->save('k', 'a', 'topicA');
        $cache->save('k', 'b', 'topicB');

        $this->assertSame(['topicA' => 'a', 'topicB' => 'b'], $cache->load('k', 3600, []));
    }

    public function testFieldsAreCaseNormalisedWithKeys(): void
    {
        // Default (case-insensitive) cache lowercases both the saved hash and the
        // requested fields, so a mixed-case field list still matches.
        $cache = new Cache(new FakeFieldAdapter());
        $cache->save('k', 'a', 'topicA');

        $this->assertSame(['topica' => 'a'], $cache->load('k', 3600, ['TopicA']));
    }

    public function testExpiredFieldsAreOmitted(): void
    {
        $cache = new Cache(new FakeFieldAdapter());
        $cache->setCaseSensitivity(true);
        $cache->save('k', 'a', 'topicA');

        // ttl 0 makes every field already stale (time + 0 > now is false).
        $this->assertSame([], $cache->load('k', 0, ['topicA']));
    }

    public function testSaveForwardsTtl(): void
    {
        $adapter = new FakeFieldAdapter();
        $cache = new Cache($adapter);
        $cache->setCaseSensitivity(true);

        $cache->save('k', 'a', 'topicA', 120);

        $this->assertSame(120, $adapter->ttls['k'] ?? 0);
        $this->assertSame('a', $cache->load('k', 3600, 'topicA'));
    }

    public function testSingleFieldLoadStillReturnsScalar(): void
    {
        $cache = new Cache(new FakeFieldAdapter());
        $cache->setCaseSensitivity(true);
        $cache->save('k', 'a', 'topicA');

        $this->assertSame('a', $cache->load('k', 3600, 'topicA'));
        $this->assertFalse($cache->load('k', 3600, 'missing'));
    }

    public function testNoneAdapterReturnsEmptyArrayForFieldList(): void
    {
        $cache = new Cache(new None());

        $this->assertSame([], $cache->load('k', 3600, ['a', 'b']));
        $this->assertFalse($cache->load('k', 3600, 'a'));
    }

    public function testMemoryAdapterToleratesFieldListAndTtl(): void
    {
        // Memory has no field concept; it must still accept the widened calls
        // without error and return an array for a field list.
        $cache = new Cache(new Memory());
        $cache->setCaseSensitivity(true);
        $this->assertNotFalse($cache->save('k', 'v', '', 60));
        $this->assertIsArray($cache->load('k', 3600, ['k']));
    }
}
