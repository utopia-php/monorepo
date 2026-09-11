<?php

declare(strict_types=1);

namespace Utopia\Cache;

interface Adapter
{
    /**
     * Load one field, or several at once. A string $hash loads a single field
     * and returns its value (or false). An array of field names loads them in
     * one call and returns a field => value map with missing/expired fields
     * omitted; an empty array loads every field. Multi-field access is a hash
     * operation: adapters that store a single value per key (Memory, Filesystem,
     * Memcached, Hazelcast, None) do not support it and return an empty map.
     *
     * @param  int  $ttl time in seconds
     * @param  string|string[]  $hash a single field, or a list of fields to batch
     * @return mixed single value, false, or array<string, mixed> for a field list
     */
    public function load(string $key, int $ttl, string|array $hash = ''): mixed;

    /**
     * Save under a single field, or several at once. A string $hash writes $data
     * to that one field. An array $hash switches to batch mode: $data is a
     * field => value map written in one call — an empty $hash writes every pair,
     * a non-empty $hash writes only those fields (values taken from $data).
     * When $ttl > 0 the key is also given a key-level expiry (adapters that
     * support it arm it; others ignore it and keep their timestamp TTL).
     * $ttl = 0 preserves the prior behaviour. Batch mode is a hash operation:
     * adapters that store a single value per key (Memory, Filesystem, Memcached,
     * Hazelcast, None) do not support it and return false.
     *
     * @param  string|array<int|string, mixed>  $data a value, or a field => value map for a field list
     * @param  string|string[]  $hash a single field, or a list of fields to batch-write
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string|array $hash = '', int $ttl = 0): bool|string|array;

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool;

    /**
     * @return string[]
     */
    public function list(string $key): array;

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool;

    public function flush(): bool;

    public function ping(): bool;

    public function getSize(): int;

    public function getName(?string $key = null): string;
}
