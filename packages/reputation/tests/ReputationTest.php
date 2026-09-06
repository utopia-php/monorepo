<?php

declare(strict_types=1);

namespace Utopia\Reputation\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Reputation\Record;
use Utopia\Reputation\Reputation;
use Utopia\Reputation\Verdict;

final class ReputationTest extends TestCase
{
    public function testGetReturnsCleanForInvalidIpWithoutCallingLookup(): void
    {
        $calls = 0;
        $reputation = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: function () use (&$calls): array {
                ++$calls;

                return ['verdict' => 'block'];
            },
        );

        $record = $reputation->get('not-an-ip');

        $this->assertSame(0, $calls);
        $this->assertSame(Verdict::Clean, $record->verdict);
        $this->assertSame('not-an-ip', $record->ip);
    }

    public function testGetReadsVerdictFromLookup(): void
    {
        $seen = [];
        $reputation = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: function (string $ip) use (&$seen): array {
                $seen[] = $ip;

                return [
                    'verdict' => 'block',
                    'score' => 100,
                    'categories' => ['tor', 'malicious'],
                ];
            },
        );

        $record = $reputation->get('185.220.101.13');

        $this->assertSame(['185.220.101.13'], $seen);
        $this->assertSame(Verdict::Block, $record->verdict);
        $this->assertSame(100, $record->score);
        $this->assertSame(['tor', 'malicious'], $record->categories);
        $this->assertSame('185.220.101.13', $record->ip);
    }

    public function testGetTreatsUnknownVerdictAndFailedLookupAsClean(): void
    {
        $unknown = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: fn(): array => ['verdict' => 'banana'],
        );
        $this->assertSame(Verdict::Clean, $unknown->get('203.0.113.10')->verdict);

        $failed = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: fn(): null => null,
        );
        $this->assertSame(Verdict::Clean, $failed->get('203.0.113.10')->verdict);
    }

    public function testGetReturnsCleanWhenMmdbIsMissing(): void
    {
        $record = new Reputation(path: '/tmp/does-not-exist-verdict.mmdb')->get('185.220.101.13');

        $this->assertSame(Verdict::Clean, $record->verdict);
        $this->assertSame(0, $record->score);
        $this->assertSame([], $record->categories);
    }

    public function testCleanFactory(): void
    {
        $record = Record::clean('8.8.8.8');

        $this->assertSame(Verdict::Clean, $record->verdict);
        $this->assertSame('8.8.8.8', $record->ip);
        $this->assertSame(0, $record->score);
        $this->assertSame([], $record->categories);
    }
}
