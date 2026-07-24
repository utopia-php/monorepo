<?php

namespace Tests\E2E\Adapter;

use Swoole\Coroutine;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Lua;

/**
 * Minimal in-memory {@see Connection} for tests, backing the broker without a
 * real Redis server. An empty pop yields so a busy receive loop doesn't starve
 * the handler coroutines.
 */
class InMemoryConnection implements Connection, Lua
{
    /** @var array<string, list<mixed>> */
    private array $lists = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    public function evaluate(string $script, array $keys = [], array $arguments = []): mixed
    {
        if (str_contains($script, '-- queue:claim')) {
            $message = $this->pop($keys[0], fromTail: true);
            if (!\is_array($message)) {
                return ['', '', '0', 'empty'];
            }

            $pid = $message['pid'];
            $encoded = json_encode($message, JSON_THROW_ON_ERROR);
            $this->hashes[$keys[1]][$pid] = $encoded;
            $this->hashes[$keys[2]][$pid] = (string) $arguments[2];

            return [$encoded, $arguments[2], '0', 'new'];
        }

        if (str_contains($script, '-- queue:acknowledge')) {
            $pid = (string) $arguments[0];
            $receipt = (string) $arguments[1];
            if (($this->hashes[$keys[1]][$pid] ?? null) !== $receipt) {
                return 0;
            }

            unset($this->hashes[$keys[0]][$pid], $this->hashes[$keys[1]][$pid]);

            return 1;
        }

        throw new \LogicException('Unsupported in-memory Lua script.');
    }

    public function rightPushArray(string $queue, array $payload): bool
    {
        $this->lists[$queue][] = $payload;

        return true;
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        $value = $this->pop($queue, fromTail: true);

        return \is_array($value) ? $value : false;
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        $value = $this->rightPopArray($queue, $timeout);
        if (\is_array($value)) {
            $this->lists[$destination] ??= [];
            array_unshift($this->lists[$destination], $value);
        }

        return $value;
    }

    public function leftPushArray(string $queue, array $payload): bool
    {
        $this->lists[$queue] ??= [];
        array_unshift($this->lists[$queue], $payload);

        return true;
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        $value = $this->pop($queue, fromTail: false);

        return \is_array($value) ? $value : false;
    }

    public function rightPush(string $queue, string $payload): bool
    {
        $this->lists[$queue][] = $payload;

        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        $value = $this->pop($queue, fromTail: true);

        return \is_string($value) ? $value : false;
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        $value = $this->rightPop($queue, $timeout);
        if (\is_string($value)) {
            $this->lists[$destination] ??= [];
            array_unshift($this->lists[$destination], $value);
        }

        return $value;
    }

    public function leftPush(string $queue, string $payload): bool
    {
        $this->lists[$queue] ??= [];
        array_unshift($this->lists[$queue], $payload);

        return true;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        $value = $this->pop($queue, fromTail: false);

        return \is_string($value) ? $value : false;
    }

    public function listRemove(string $queue, string $key): bool
    {
        $list = $this->lists[$queue] ?? [];
        $index = array_search($key, $list, true);
        if ($index === false) {
            return false;
        }

        unset($list[$index]);
        $this->lists[$queue] = array_values($list);

        return true;
    }

    public function listSize(string $key): int
    {
        return \count($this->lists[$key] ?? []);
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        return \array_slice($this->lists[$key] ?? [], $offset, $total);
    }

    public function remove(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function get(string $key): array|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function increment(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function decrement(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) - 1;
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void {}

    /** Pop from either end, yielding when empty so the receive loop doesn't spin. */
    private function pop(string $queue, bool $fromTail): mixed
    {
        if (empty($this->lists[$queue])) {
            if (Coroutine::getCid() !== -1) {
                Coroutine::sleep(0.005);
            }

            return null;
        }

        return $fromTail ? array_pop($this->lists[$queue]) : array_shift($this->lists[$queue]);
    }
}
