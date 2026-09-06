<?php

declare(strict_types=1);

namespace Utopia\Reputation\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Reputation\Record;
use Utopia\Reputation\Reputation;
use Utopia\Reputation\Verdict;

final class ReputationTest extends TestCase
{
    public function testGetDoesNotSearchUntilAFieldIsRead(): void
    {
        $calls = 0;
        $reputation = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: function (string $ip) use (&$calls): array {
                ++$calls;

                return [
                    'verdict' => 'block',
                    'score' => 100,
                    'categories' => ['tor'],
                ];
            },
        );

        $record = $reputation->get('185.220.101.13');

        $this->assertSame(0, $calls);
        $this->assertSame('185.220.101.13', $record->getIp());
        $this->assertSame(0, $calls);

        $this->assertSame(Verdict::Block, $record->getVerdict());
        $this->assertSame(1, $calls);
        $this->assertSame(100, $record->getScore());
        $this->assertSame(['tor'], $record->getCategories());
        $this->assertSame(1, $calls);
    }

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

        $this->assertSame(Verdict::Clean, $record->getVerdict());
        $this->assertSame('not-an-ip', $record->getIp());
        $this->assertSame(0, $calls);
    }

    public function testGetTreatsUnknownVerdictAndFailedLookupAsClean(): void
    {
        $unknown = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: fn(): array => ['verdict' => 'banana'],
        );
        $this->assertSame(Verdict::Clean, $unknown->get('203.0.113.10')->getVerdict());

        $failed = new Reputation(
            path: '/tmp/does-not-exist-verdict.mmdb',
            lookup: fn(): null => null,
        );
        $this->assertSame(Verdict::Clean, $failed->get('203.0.113.10')->getVerdict());
    }

    public function testGetReturnsCleanWhenMmdbIsMissing(): void
    {
        $record = new Reputation(path: '/tmp/does-not-exist-verdict.mmdb')->get('185.220.101.13');

        $this->assertSame(Verdict::Clean, $record->getVerdict());
        $this->assertSame(0, $record->getScore());
        $this->assertSame([], $record->getCategories());
    }

    public function testCleanFactoryDoesNotSearch(): void
    {
        $record = Record::clean('8.8.8.8');

        $this->assertSame(Verdict::Clean, $record->getVerdict());
        $this->assertSame('8.8.8.8', $record->getIp());
        $this->assertSame(0, $record->getScore());
        $this->assertSame([], $record->getCategories());
    }
}
