<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class None implements Adapter
{
    /**
     * @param  int  $ttl time in seconds
     * @param  string|string[]  $hash a single field, or a list of fields to batch
     * @return mixed false, or an empty array<string, mixed> for a field list
     */
    public function load(string $key, int $ttl, string|array $hash = ''): mixed
    {
        return \is_array($hash) ? [] : false;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string|array $hash = '', int $ttl = 0): bool|string|array
    {
        return false;
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        return false;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        return 0;
    }

    public function getName(?string $key = null): string
    {
        return 'none';
    }
}
