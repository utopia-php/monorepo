<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Memory implements Adapter
{
    /**
     * @var array<string, mixed>
     */
    public $store = [];

    /**
     * @param  int  $ttl time in seconds
     * @param  string|string[]  $hash a single field, or a list of fields to batch
     * @return mixed single value, false, or array<string, mixed> for a field list
     */
    public function load(string $key, int $ttl, string|array $hash = ''): mixed
    {
        if (\is_array($hash)) {
            $fields = $hash === [] ? $this->list($key) : $hash;
            $result = [];
            foreach ($fields as $field) {
                $value = $this->load($key, $ttl, $field);
                if ($value !== false) {
                    $result[$field] = $value;
                }
            }

            return $result;
        }

        if ($key !== '' && $key !== '0' && isset($this->store[$key])) {
            /** @var array{time: int, data: string} */
            $saved = $this->store[$key];

            return ($saved['time'] + $ttl > time()) ? $saved['data'] : false; // return data if cache is valid
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string|array $hash = '', int $ttl = 0): bool|string|array
    {
        if ($key === '' || $key === '0' || empty($data)) {
            return false;
        }

        if (\is_array($hash)) {
            if (! \is_array($data)) {
                return false;
            }
            $fields = $hash === [] ? array_keys($data) : $hash;
            $ok = false;
            foreach ($fields as $field) {
                $field = (string) $field;
                if (\array_key_exists($field, $data) && $this->save($key, $data[$field], $field, $ttl) !== false) {
                    $ok = true;
                }
            }

            return $ok ? $data : false;
        }

        $saved = [
            'time' => time(),
            'data' => $data,
        ];

        $this->store[$key] = $saved;

        return $data;
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        if ($key === '' || $key === '0' || ! isset($this->store[$key])) {
            return false;
        }

        /** @var array{time: int, data: string|array<int|string, mixed>} $saved */
        $saved = $this->store[$key];
        $saved['time'] = time();
        $this->store[$key] = $saved;

        return true;
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
        if ($key !== '' && $key !== '0' && isset($this->store[$key])) { // if a key is passed and it exists in cache
            unset($this->store[$key]);

            return true;
        }

        return false;
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

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        return \count($this->store);
    }

    public function getName(?string $key = null): string
    {
        return 'memory';
    }
}
