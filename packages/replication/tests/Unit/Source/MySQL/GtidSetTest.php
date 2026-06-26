<?php

declare(strict_types=1);

namespace Utopia\Replication\Tests\Unit\Source\MySQL;

use PHPUnit\Framework\TestCase;
use Utopia\Replication\Source\MySQL\GtidSet;

final class GtidSetTest extends TestCase
{
    private const string SID = '3e11fa47-71ca-11e1-9e33-c80aa9429562';

    public function testParseAndStringRoundTrip(): void
    {
        $set = new GtidSet(self::SID . ':1-5:7-9');

        $this->assertSame(self::SID . ':1-5:7-9', (string) $set);
    }

    public function testAddMergesAdjacentTransactions(): void
    {
        $set = new GtidSet(self::SID . ':1-5');
        $set->add(self::SID, 6); // adjacent => extends the interval
        $set->add(self::SID, 8); // gap => new interval

        $this->assertSame(self::SID . ':1-6:8', (string) $set);
    }

    public function testAddCollapsesGap(): void
    {
        $set = new GtidSet(self::SID . ':1-5:7-9');
        $set->add(self::SID, 6); // fills the gap, merging both intervals

        $this->assertSame(self::SID . ':1-9', (string) $set);
    }

    public function testEmptySet(): void
    {
        $set = new GtidSet();

        $this->assertTrue($set->isEmpty());
        $this->assertSame('', (string) $set);
        // n_sids = 0 encoded as 8 little-endian bytes.
        $this->assertSame(pack('P', 0), $set->encode());
    }

    public function testEncodeUsesHalfOpenIntervals(): void
    {
        $set = new GtidSet(self::SID . ':1-5');

        $expected = pack('P', 1)                                   // one sid
            . hex2bin(str_replace('-', '', self::SID))            // 16-byte uuid
            . pack('P', 1)                                         // one interval
            . pack('P', 1)                                         // start
            . pack('P', 6);                                        // end = last + 1

        $this->assertSame($expected, $set->encode());
    }

    public function testSingleTransactionInterval(): void
    {
        $set = new GtidSet(self::SID . ':42');

        $this->assertSame(self::SID . ':42', (string) $set);
    }

    public function testUppercaseUuidIsNormalisedToLowercase(): void
    {
        // MySQL reports gtid_executed with uppercase UUIDs (e.g. SHOW BINARY LOG STATUS).
        $set = new GtidSet('3E11FA47-71CA-11E1-9E33-C80AA9429562:1-42');

        $this->assertSame('3e11fa47-71ca-11e1-9e33-c80aa9429562:1-42', (string) $set);
    }

    public function testMultipleSidsAreKeptSeparate(): void
    {
        $other = '11111111-2222-3333-4444-555555555555';
        $set = new GtidSet(self::SID . ':1-3,' . $other . ':5-6');

        $this->assertSame(self::SID . ':1-3,' . $other . ':5-6', (string) $set);
        // Two sids in the encoded header.
        $header = unpack('P', substr($set->encode(), 0, 8));
        $this->assertNotFalse($header);
        $this->assertSame(2, $header[1]);
    }

    public function testAddToUnseenSidCreatesEntry(): void
    {
        $set = new GtidSet();
        $set->add(self::SID, 1);

        $this->assertFalse($set->isEmpty());
        $this->assertSame(self::SID . ':1', (string) $set);
    }

    public function testAddIsOrderIndependent(): void
    {
        $set = new GtidSet();
        $set->add(self::SID, 3);
        $set->add(self::SID, 1);
        $set->add(self::SID, 2); // fills the gap regardless of insertion order

        $this->assertSame(self::SID . ':1-3', (string) $set);
    }

    public function testAddMatchesSidCaseInsensitively(): void
    {
        $set = new GtidSet(self::SID . ':1-3');
        $set->add(strtoupper(self::SID), 4); // same source, uppercased

        $this->assertSame(self::SID . ':1-4', (string) $set);
    }

    public function testEncodeEmitsEveryInterval(): void
    {
        $set = new GtidSet(self::SID . ':1-2:5-6');

        $expected = pack('P', 1)
            . hex2bin(str_replace('-', '', self::SID))
            . pack('P', 2)        // two intervals
            . pack('P', 1) . pack('P', 3)   // [1,2] -> half-open [1,3)
            . pack('P', 5) . pack('P', 7);  // [5,6] -> half-open [5,7)

        $this->assertSame($expected, $set->encode());
    }
}
