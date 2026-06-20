<?php

namespace Utopia\Replication\Tests\Unit\Source\MySQL;

use PHPUnit\Framework\TestCase;
use Utopia\Replication\Change;
use Utopia\Replication\Source\MySQL\Constants;
use Utopia\Replication\Source\MySQL\Decoder;
use Utopia\Replication\Source\MySQL\EventParser;
use Utopia\Replication\Source\MySQL\GtidSet;

class DecoderTest extends TestCase
{
    use BinlogFixtures;

    private const string SCHEMA = 'appwrite';
    private const string TABLE = 'projects';
    private const int TABLE_ID = 7;
    private const string SID = '00112233-4455-6677-8899-aabbccddeeff';
    private const string SID_HEX = '00112233445566778899aabbccddeeff';

    public function testDecodesWriteRowsIntoAnInsertChange(): void
    {
        $decoder = $this->decoder();

        $this->assertNull($decoder->decode($this->tableMapEvent()));
        $change = $decoder->decode($this->writeEvent(1, 'a'));

        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(Change::INSERT, $change->action);
        $this->assertSame(self::SCHEMA, $change->database);
        $this->assertSame(self::TABLE, $change->table);
        $this->assertSame(['_id' => 1, '_uid' => 'a'], $change->rows[0]);
    }

    public function testUpdateYieldsAfterImage(): void
    {
        $decoder = $this->decoder();
        $decoder->decode($this->tableMapEvent());

        $body = $this->binlogUpdateV2(
            self::TABLE_ID,
            2,
            $this->binlogRow(2, pack('P', 1), $this->varchar('old')), // before
            $this->binlogRow(2, pack('P', 1), $this->varchar('new')), // after
        );
        $change = $decoder->decode($this->binlogEvent(Constants::UPDATE_ROWS_EVENT_V2, $body));

        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(Change::UPDATE, $change->action);
        $this->assertSame('new', $change->rows[0]['_uid']);
    }

    public function testDeleteRowsYieldsDeleteChange(): void
    {
        $decoder = $this->decoder();
        $decoder->decode($this->tableMapEvent());

        $body = $this->binlogRowsV2(self::TABLE_ID, 2, $this->binlogRow(2, pack('P', 9), $this->varchar('gone')));
        $change = $decoder->decode($this->binlogEvent(Constants::DELETE_ROWS_EVENT_V2, $body));

        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(Change::DELETE, $change->action);
        $this->assertSame(9, $change->rows[0]['_id']);
    }

    public function testNonRowEventsYieldNull(): void
    {
        $decoder = $this->decoder();

        $this->assertNull($decoder->decode($this->binlogEvent(Constants::ROTATE_EVENT, str_repeat("\x00", 30))));
        $this->assertNull($decoder->decode($this->binlogEvent(Constants::QUERY_EVENT, str_repeat("\x00", 30))));
        $this->assertNull($decoder->decode($this->binlogEvent(Constants::FORMAT_DESCRIPTION_EVENT, str_repeat("\x00", 80))));
    }

    public function testRowsWithoutAPriorTableMapAreSkipped(): void
    {
        $decoder = $this->decoder();

        // No TABLE_MAP seen for this table id yet (e.g. starting mid-stream).
        $this->assertNull($decoder->decode($this->writeEvent(1, 'a')));
    }

    public function testCheckpointStaysEmptyUntilCommit(): void
    {
        $decoder = $this->decoder();

        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 5)));
        $decoder->decode($this->tableMapEvent());
        $change = $decoder->decode($this->writeEvent(1, 'a'));

        // The change reflects transactions committed *before* it — none yet.
        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame('', $change->gtid);
        $this->assertSame('', $decoder->position());
    }

    public function testCheckpointAdvancesOnXidCommit(): void
    {
        $decoder = $this->decoder();

        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 5)));
        $decoder->decode($this->tableMapEvent());
        $decoder->decode($this->writeEvent(1, 'a'));
        $decoder->decode($this->binlogEvent(Constants::XID_EVENT, pack('P', 1)));

        $this->assertSame(self::SID . ':5', $decoder->position());

        // A change in the next transaction now carries the first commit.
        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 6)));
        $change = $decoder->decode($this->writeEvent(2, 'b'));
        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(self::SID . ':5', $change->gtid);
    }

    public function testCheckpointDoesNotAdvanceWithoutXid(): void
    {
        $decoder = $this->decoder();

        // A GTID event that never reaches its XID must not move the checkpoint.
        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 5)));
        $decoder->decode($this->tableMapEvent());
        $decoder->decode($this->writeEvent(1, 'a'));

        $this->assertSame('', $decoder->position());
    }

    public function testSeededPositionIsCarriedAndExtended(): void
    {
        $decoder = new Decoder(new EventParser(), new GtidSet(self::SID . ':1-4'), self::SCHEMA, true);
        $decoder->decode($this->tableMapEvent());

        // A change before any new commit reports the seeded checkpoint.
        $change = $decoder->decode($this->writeEvent(1, 'a'));
        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(self::SID . ':1-4', $change->gtid);

        // Committing gno 5 extends the seeded interval contiguously.
        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 5)));
        $decoder->decode($this->binlogEvent(Constants::XID_EVENT, pack('P', 1)));
        $this->assertSame(self::SID . ':1-5', $decoder->position());
    }

    public function testSchemaFilterDropsOtherDatabases(): void
    {
        $decoder = $this->decoder();

        // TABLE_MAP for a different schema -> its rows are decoded but not emitted.
        $decoder->decode($this->binlogEvent(Constants::TABLE_MAP_EVENT, $this->binlogTableMap(self::TABLE_ID, 'other', self::TABLE, $this->columns())));
        $this->assertNull($decoder->decode($this->writeEvent(1, 'a')));
    }

    public function testNullSchemaEmitsEveryDatabase(): void
    {
        $decoder = new Decoder(new EventParser(), new GtidSet(), null, true);
        $decoder->decode($this->binlogEvent(Constants::TABLE_MAP_EVENT, $this->binlogTableMap(self::TABLE_ID, 'anything', self::TABLE, $this->columns())));

        $change = $decoder->decode($this->writeEvent(1, 'a'));
        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame('anything', $change->database);
    }

    public function testChecksumDisabledKeepsTrailingBytes(): void
    {
        // With checksum off the decoder must not strip a CRC, so events carry none.
        $decoder = new Decoder(new EventParser(), new GtidSet(), self::SCHEMA, false);
        $decoder->decode($this->binlogEvent(Constants::TABLE_MAP_EVENT, $this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $this->columns()), checksum: false));

        $body = $this->binlogRowsV2(self::TABLE_ID, 2, $this->binlogRow(2, pack('P', 1), $this->varchar('a')));
        $change = $decoder->decode($this->binlogEvent(Constants::WRITE_ROWS_EVENT_V2, $body, checksum: false));

        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(['_id' => 1, '_uid' => 'a'], $change->rows[0]);
    }

    public function testHandlesV1RowEvents(): void
    {
        $decoder = $this->decoder();
        $decoder->decode($this->tableMapEvent());

        // v1 ROWS events carry no 2-byte extra-data header.
        $body = $this->le(self::TABLE_ID, 6) . "\x00\x00" . \chr(2) . \chr(0b11)
            . $this->binlogRow(2, pack('P', 3), $this->varchar('v1'));
        $change = $decoder->decode($this->binlogEvent(Constants::WRITE_ROWS_EVENT_V1, $body));

        $this->assertInstanceOf(Change::class, $change);
        $this->assertSame(Change::INSERT, $change->action);
        $this->assertSame('v1', $change->rows[0]['_uid']);
    }

    public function testAutocommittedStatementCommitsOnItsOwnQueryEvent(): void
    {
        $decoder = $this->decoder();

        // A DDL transaction is GTID + QUERY with no XID — autocommitted, so the
        // QUERY itself is the commit boundary (even if it ends the segment).
        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 5)));
        $decoder->decode($this->binlogEvent(Constants::QUERY_EVENT, $this->binlogQueryEvent('CREATE TABLE t (id INT)')));

        $this->assertSame(self::SID . ':5', $decoder->position());
    }

    public function testBeginOpenedRowTransactionCommitsOnlyOnXid(): void
    {
        $decoder = $this->decoder();

        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 5)));
        $decoder->decode($this->binlogEvent(Constants::QUERY_EVENT, $this->binlogQueryEvent('BEGIN')));
        $decoder->decode($this->tableMapEvent());
        $decoder->decode($this->writeEvent(1, 'a'));

        // BEGIN must not commit; only the XID does.
        $this->assertSame('', $decoder->position());

        $decoder->decode($this->binlogEvent(Constants::XID_EVENT, pack('P', 1)));
        $this->assertSame(self::SID . ':5', $decoder->position());
    }

    public function testInterruptedRowTransactionIsNotCommittedByALaterGtid(): void
    {
        $decoder = $this->decoder();

        // Transaction 7 is a row transaction cut off before its XID.
        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 7)));
        $decoder->decode($this->binlogEvent(Constants::QUERY_EVENT, $this->binlogQueryEvent('BEGIN')));
        $decoder->decode($this->tableMapEvent());
        $decoder->decode($this->writeEvent(1, 'a'));

        // A later GTID must NOT mark the incomplete transaction 7 as executed.
        $decoder->decode($this->binlogEvent(Constants::GTID_EVENT, $this->binlogGtidEvent(self::SID_HEX, 8)));
        $this->assertSame('', $decoder->position());
    }

    private function decoder(): Decoder
    {
        return new Decoder(new EventParser(), new GtidSet(), self::SCHEMA, true);
    }

    /**
     * @return list<array{type: int, meta?: string, name: string}>
     */
    private function columns(): array
    {
        return [
            ['type' => Constants::TYPE_LONGLONG, 'name' => '_id'],
            ['type' => Constants::TYPE_VAR_STRING, 'meta' => pack('v', 1020), 'name' => '_uid'],
        ];
    }

    private function tableMapEvent(): string
    {
        return $this->binlogEvent(Constants::TABLE_MAP_EVENT, $this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $this->columns()));
    }

    private function writeEvent(int $id, string $uid): string
    {
        $body = $this->binlogRowsV2(self::TABLE_ID, 2, $this->binlogRow(2, pack('P', $id), $this->varchar($uid)));

        return $this->binlogEvent(Constants::WRITE_ROWS_EVENT_V2, $body);
    }

    /** A VAR_STRING value with a 2-byte length prefix (metadata 1020 > 255). */
    private function varchar(string $value): string
    {
        return pack('v', \strlen($value)) . $value;
    }
}
