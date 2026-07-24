<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker\Redis;

use Utopia\Queue\Queue;

final class Keys
{
    /** @var array<int, string> */
    private static array $tags = [];

    private function __construct(
        public readonly string $pending,
        public readonly string $ledger,
        public readonly string $processing,
        public readonly string $receipts,
        public readonly string $visibility,
        public readonly string $expiry,
        public readonly string $failed,
    ) {}

    public static function from(Queue $queue): self
    {
        $pending = "{$queue->namespace}.queue.{$queue->name}";
        $tag = self::tag($pending);

        return new self(
            pending: $pending,
            ledger: "{$tag}.once",
            processing: "{$tag}.processing",
            receipts: "{$tag}.receipts",
            visibility: "{$tag}.visibility",
            expiry: "{$tag}.expiry",
            failed: "{$tag}.failed",
        );
    }

    private static function tag(string $key): string
    {
        $open = strpos($key, '{');
        if ($open !== false) {
            $close = strpos($key, '}', $open + 1);
            if ($close !== false && $close > $open + 1) {
                return substr($key, $open, $close - $open + 1);
            }
        }

        if (!str_contains($key, '}')) {
            return '{' . $key . '}';
        }

        $slot = self::slot($key);
        if (isset(self::$tags[$slot])) {
            return self::$tags[$slot];
        }

        for ($candidate = 0; ; $candidate++) {
            $tag = "queue-{$candidate}";
            if (self::slot($tag) === $slot) {
                return self::$tags[$slot] = '{' . $tag . '}';
            }
        }
    }

    private static function slot(string $key): int
    {
        $open = strpos($key, '{');
        if ($open !== false) {
            $close = strpos($key, '}', $open + 1);
            if ($close !== false && $close > $open + 1) {
                $key = substr($key, $open + 1, $close - $open - 1);
            }
        }

        $checksum = 0;
        foreach (unpack('C*', $key) ?: [] as $byte) {
            $checksum ^= $byte << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x8000) !== 0
                    ? (($checksum << 1) ^ 0x1021) & 0xffff
                    : ($checksum << 1) & 0xffff;
            }
        }

        return $checksum % 16_384;
    }
}
