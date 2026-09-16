<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter\Redis;

/**
 * Per-process memo of decoded envelopes, keyed by cache key.
 *
 * A hot key (collection metadata, the project, the user) is read from Redis
 * on every request and its JSON decoded every time, although the stored
 * string rarely changes. Redis stays the source of truth: every load still
 * fetches the string, and the decoded value is reused only while the string
 * is byte-for-byte the one it was decoded from. A purge or a save elsewhere
 * changes the string and the next load decodes again, so nothing here can
 * serve a stale value that Redis would not.
 *
 * The comparison is a length check and a memcmp, which costs nothing next to
 * decoding kilobytes of JSON. Small payloads are cheap to decode and would
 * only churn the memo, so they are passed straight through.
 */
final class DecodedEnvelopes
{
    /**
     * @var array<string, array{string, int, mixed}> cache key => [raw envelope, envelope time, data]
     */
    private array $entries = [];

    public function __construct(
        private readonly int $capacity = 128,
        private readonly int $minimumBytes = 1024,
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('Capacity must be at least 1.');
        }
    }

    /**
     * Decode $value the way {@see Envelope::decode()} does, reusing the memo
     * entry for $key when the stored string has not changed.
     */
    public function decode(string $key, string $value, int $ttl, int $now): mixed
    {
        if (\strlen($value) < $this->minimumBytes) {
            return Envelope::decode($value, $ttl, $now);
        }

        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && $entry[0] === $value) {
            return $entry[1] + $ttl > $now ? $entry[2] : false;
        }

        $envelope = Envelope::unwrap($value);
        if ($envelope === null) {
            return false;
        }

        unset($this->entries[$key]);
        if (\count($this->entries) >= $this->capacity) {
            unset($this->entries[array_key_first($this->entries)]);
        }
        $this->entries[$key] = [$value, $envelope['time'], $envelope['data']];

        return $envelope['time'] + $ttl > $now ? $envelope['data'] : false;
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
