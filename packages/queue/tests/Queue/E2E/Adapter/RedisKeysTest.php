<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis\Keys;
use Utopia\Queue\Queue;

final class RedisKeysTest extends TestCase
{
    public function testExplicitHashTagKeysIncludeExactQueueIdentity(): void
    {
        $first = new Queue('first{shared}a', 'dat1993review');
        $firstPending = 'dat1993review.queue.first{shared}a';
        $firstBase = '{shared}.queue-' . hash('sha256', $firstPending);
        $firstKeys = $this->values(Keys::from($first));

        $this->assertSame([
            $firstPending,
            "{$firstBase}.once",
            "{$firstBase}.processing",
            "{$firstBase}.receipts",
            "{$firstBase}.visibility",
            "{$firstBase}.expiry",
            "{$firstBase}.failed",
        ], $firstKeys);
        $this->assertSame($firstKeys, $this->values(Keys::from($first)));

        $second = new Queue('second{shared}b', 'dat1993review');
        $secondPending = 'dat1993review.queue.second{shared}b';
        $secondBase = '{shared}.queue-' . hash('sha256', $secondPending);
        $secondKeys = $this->values(Keys::from($second));

        $this->assertSame([
            $secondPending,
            "{$secondBase}.once",
            "{$secondBase}.processing",
            "{$secondBase}.receipts",
            "{$secondBase}.visibility",
            "{$secondBase}.expiry",
            "{$secondBase}.failed",
        ], $secondKeys);
        $this->assertSame($secondKeys, $this->values(Keys::from($second)));

        foreach (array_keys(\array_slice($firstKeys, 1, preserve_keys: true)) as $index) {
            $this->assertNotSame($firstKeys[$index], $secondKeys[$index]);
        }
    }

    public function testExplicitHashTagParsingUsesTheFirstOpeningAndFollowingClosingBrace(): void
    {
        $queue = new Queue('nested{{bar}}tail', 'keys');
        $pending = 'keys.queue.nested{{bar}}tail';
        $base = '{{bar}.queue-' . hash('sha256', $pending);

        $this->assertSame([
            $pending,
            "{$base}.once",
            "{$base}.processing",
            "{$base}.receipts",
            "{$base}.visibility",
            "{$base}.expiry",
            "{$base}.failed",
        ], $this->values(Keys::from($queue)));
    }

    public function testOrdinaryAndMalformedKeyNamesRemainUnchanged(): void
    {
        $this->assertSame([
            'keys.queue.plain',
            '{keys.queue.plain}.once',
            '{keys.queue.plain}.processing',
            '{keys.queue.plain}.receipts',
            '{keys.queue.plain}.visibility',
            '{keys.queue.plain}.expiry',
            '{keys.queue.plain}.failed',
        ], $this->values(Keys::from(new Queue('plain', 'keys'))));

        $emptyBase = '{queue-4fa39702a56eb52b69c5b929052fac21ee1976fa823de78c5ea13d2ddcb1b2e9-11282}';
        $this->assertSame([
            'keys.queue.empty{}tag',
            "{$emptyBase}.once",
            "{$emptyBase}.processing",
            "{$emptyBase}.receipts",
            "{$emptyBase}.visibility",
            "{$emptyBase}.expiry",
            "{$emptyBase}.failed",
        ], $this->values(Keys::from(new Queue('empty{}tag', 'keys'))));

        $emptyThenTaggedBase = '{queue-a429fd0c6f8d7ac8e892efe60288a8300369de135cb2aac169411338a13713b1-28059}';
        $this->assertSame([
            'keys.queue.empty{}{bar}',
            "{$emptyThenTaggedBase}.once",
            "{$emptyThenTaggedBase}.processing",
            "{$emptyThenTaggedBase}.receipts",
            "{$emptyThenTaggedBase}.visibility",
            "{$emptyThenTaggedBase}.expiry",
            "{$emptyThenTaggedBase}.failed",
        ], $this->values(Keys::from(new Queue('empty{}{bar}', 'keys'))));

        $closingBase = '{queue-fe8071e1812350a96bfdf172f755736cc7d13e4f8822ae5a941d685a35e61c29-11925}';
        $this->assertSame([
            'keys.queue.close}tag',
            "{$closingBase}.once",
            "{$closingBase}.processing",
            "{$closingBase}.receipts",
            "{$closingBase}.visibility",
            "{$closingBase}.expiry",
            "{$closingBase}.failed",
        ], $this->values(Keys::from(new Queue('close}tag', 'keys'))));

        $this->assertSame([
            'keys.queue.open{tag',
            '{keys.queue.open{tag}.once',
            '{keys.queue.open{tag}.processing',
            '{keys.queue.open{tag}.receipts',
            '{keys.queue.open{tag}.visibility',
            '{keys.queue.open{tag}.expiry',
            '{keys.queue.open{tag}.failed',
        ], $this->values(Keys::from(new Queue('open{tag', 'keys'))));
    }

    /**
     * @return list<string>
     */
    private function values(Keys $keys): array
    {
        return [
            $keys->pending,
            $keys->ledger,
            $keys->processing,
            $keys->receipts,
            $keys->visibility,
            $keys->expiry,
            $keys->failed,
        ];
    }
}
