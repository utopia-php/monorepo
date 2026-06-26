<?php

declare(strict_types=1);

namespace Utopia\Replication\Tests\Unit\Source\MySQL;

use PHPUnit\Framework\TestCase;
use Utopia\Replication\Change;
use Utopia\Replication\Exception;
use Utopia\Replication\Source\MySQL\Constants;
use Utopia\Replication\Source\MySQL\Decoder;
use Utopia\Replication\Source\MySQL\EventParser;
use Utopia\Replication\Source\MySQL\File;
use Utopia\Replication\Source\MySQL\GtidSet;

/**
 * Decodes a hand-built binlog file end to end: {@see File} frames the events,
 * {@see Decoder} turns them into changes — no socket, no server. This is the
 * offline path edge uses to read archived binlogs.
 */
final class FileTest extends TestCase
{
    use BinlogFixtures;

    private const int TABLE_ID = 42;
    private const string SCHEMA = 'appwrite';
    private const string TABLE = 'console15x_projects';
    private const string SID_HEX = '00112233445566778899aabbccddeeff';

    public function testDecodesAFullBinlogFromAString(): void
    {
        $changes = $this->drain(new File($this->binlog()));

        $this->assertCount(2, $changes);
        $this->assertSame(Change::INSERT, $changes[0]->action);
        $this->assertSame(self::SCHEMA, $changes[0]->database);
        $this->assertSame(self::TABLE, $changes[0]->table);
        $this->assertSame(100, $changes[0]->rows[0]['_id']);
        $this->assertSame('proj123', $changes[0]->rows[0]['_uid']);

        // The checkpoint reflects transactions committed *before* each change, so
        // the first is empty and the second carries the first transaction's GTID.
        $this->assertSame('', $changes[0]->gtid);
        $this->assertSame('00112233-4455-6677-8899-aabbccddeeff:5', $changes[1]->gtid);
    }

    public function testReassemblesEventsAcrossArbitraryChunkBoundaries(): void
    {
        $binlog = $this->binlog();
        $chunks = (function () use ($binlog): \Generator {
            // 7 bytes at a time splits every event header mid-field.
            foreach (str_split($binlog, 7) as $chunk) {
                yield $chunk;
            }
        })();

        $changes = $this->drain(new File($chunks));

        $this->assertCount(2, $changes);
        $this->assertSame('proj123', $changes[0]->rows[0]['_uid']);
        $this->assertSame('proj456', $changes[1]->rows[0]['_uid']);
    }

    public function testDetectsChecksumFromFormatDescriptionEvent(): void
    {
        $source = new File($this->binlog());
        $source->open();

        $this->assertTrue($source->checksum());
    }

    public function testRejectsNonBinlogBytes(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('bad magic header');

        (new File('not a binlog'))->open();
    }

    public function testTruncatedEventBodyThrows(): void
    {
        $binlog = $this->binlog();

        $source = new File(substr($binlog, 0, -3)); // chop the last event's tail
        $source->open();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Truncated binlog');

        iterator_to_array($source->events());
    }

    /**
     * @return array<int, Change>
     */
    private function drain(File $source): array
    {
        $source->open();
        $decoder = new Decoder(new EventParser(), new GtidSet(), self::SCHEMA, $source->checksum());

        $changes = [];
        foreach ($source->events() as $event) {
            $change = $decoder->decode($event);
            if ($change instanceof \Utopia\Replication\Change) {
                $changes[] = $change;
            }
        }
        $source->close();

        return $changes;
    }

    /**
     * A complete CRC32-checksummed binlog: magic, FDE, then two committed INSERTs
     * (each a GTID, TABLE_MAP, WRITE_ROWS, XID quartet) so the test can observe
     * the checkpoint advancing from one transaction to the next.
     */
    private function binlog(): string
    {
        // FDE body must end with the checksum-algorithm byte (1 = CRC32); the
        // event() helper then appends the 4-byte CRC trailer after it.
        $fde = str_repeat("\x00", 50) . \chr(1);

        return $this->MAGIC()
            . $this->event(Constants::FORMAT_DESCRIPTION_EVENT, $fde)
            . $this->transaction(5, 100, 'proj123')
            . $this->transaction(6, 101, 'proj456');
    }

    /**
     * One committed INSERT: GTID(gno) → TABLE_MAP → WRITE_ROWS(row) → XID.
     */
    private function transaction(int $gno, int $id, string $uid): string
    {
        $gtid = "\x00"                              // commit flag
            . hex2bin(self::SID_HEX)                // source UUID (16 bytes)
            . pack('P', $gno);                      // transaction number (gno)

        return $this->event(Constants::GTID_EVENT, $gtid)
            . $this->event(Constants::TABLE_MAP_EVENT, $this->tableMapBody())
            . $this->event(Constants::WRITE_ROWS_EVENT_V2, $this->rowsHeader() . $this->cell($id, $uid))
            . $this->event(Constants::XID_EVENT, pack('P', 1));
    }

    private function MAGIC(): string
    {
        return "\xfe\x62\x69\x6e";
    }

    /**
     * Wrap a body in a 19-byte event header and a 4-byte CRC trailer, filling the
     * self-declared event_size field the file reader frames on.
     */
    private function event(int $type, string $body): string
    {
        $eventSize = Constants::EVENT_HEADER_SIZE + \strlen($body) + 4;

        $header = "\x00\x00\x00\x00"      // timestamp
            . \chr($type)                 // event type
            . "\x00\x00\x00\x00"          // server id
            . pack('V', $eventSize)       // event size (header + body + CRC)
            . "\x00\x00\x00\x00"          // log position
            . "\x00\x00";                 // flags

        return $header . $body . "\xDE\xAD\xBE\xEF"; // dummy CRC; the decoder strips, doesn't verify
    }

    /**
     * Two-column table (`_id` BIGINT, `_uid` VARCHAR) with FULL column-name
     * metadata — mirrors the EventParser fixture.
     */
    private function tableMapBody(): string
    {
        $body = $this->uint(self::TABLE_ID, 6)
            . "\x00\x00"
            . \chr(\strlen(self::SCHEMA)) . self::SCHEMA . "\x00"
            . \chr(\strlen(self::TABLE)) . self::TABLE . "\x00"
            . \chr(2)
            . \chr(Constants::TYPE_LONGLONG) . \chr(Constants::TYPE_VAR_STRING);

        $metadata = pack('v', 1020);
        $body .= pack('C', \strlen($metadata)) . $metadata;
        $body .= "\x00"; // null bitmap

        $body .= \chr(Constants::METADATA_SIGNEDNESS) . \chr(1) . "\x00";
        $names = \chr(3) . '_id' . \chr(4) . '_uid';

        return $body . (\chr(Constants::METADATA_COLUMN_NAME) . pack('C', \strlen($names)) . $names);
    }

    private function rowsHeader(): string
    {
        return $this->uint(self::TABLE_ID, 6)
            . "\x00\x00"   // flags
            . "\x02\x00"   // v2 extra-data length = 2 (none)
            . \chr(2)      // column count
            . \chr(0b11);  // both columns present
    }

    private function cell(int $id, string $uid): string
    {
        return "\x00" . pack('P', $id) . pack('v', \strlen($uid)) . $uid;
    }

    private function uint(int $value, int $bytes): string
    {
        $out = '';
        for ($i = 0; $i < $bytes; $i++) {
            $out .= \chr(($value >> ($i * 8)) & 0xFF);
        }

        return $out;
    }

    public function testDecodesAChecksumOffBinlog(): void
    {
        $changes = $this->drain(new File($this->insertBinlog(checksum: false)));

        $this->assertCount(1, $changes);
        $this->assertSame('x', $changes[0]->rows[0]['_uid']);

        $probe = new File($this->insertBinlog(checksum: false));
        $probe->open();
        $this->assertFalse($probe->checksum());
    }

    public function testPositionIsEmptyAndCloseIsANoop(): void
    {
        $source = new File($this->insertBinlog(checksum: true));
        $source->open();

        $this->assertSame('', $source->position()); // a file carries no resume token
        $source->close();                            // the caller owns any stream; nothing to release
        $source->close();                            // idempotent
    }

    public function testIgnoresATrailingRotateEvent(): void
    {
        // Real binlog files end with a ROTATE pointing at the next file.
        $binlog = $this->insertBinlog(checksum: true)
            . $this->binlogEvent(Constants::ROTATE_EVENT, str_repeat("\x00", 30));

        $changes = $this->drain(new File($binlog));

        $this->assertCount(1, $changes); // the INSERT decoded; ROTATE skipped cleanly
    }

    public function testFramesALargeEventSplitAcrossManyChunks(): void
    {
        // A single event far larger than a chunk: framing must follow event_size,
        // not chunk boundaries (a file has no 16 MiB packet split like the wire).
        $payload = str_repeat('Z', 100_000);
        $columns = [['type' => Constants::TYPE_BLOB, 'meta' => \chr(3), 'name' => 'data']];
        $value = $this->le(\strlen($payload), 3) . $payload; // 3-byte length prefix

        $binlog = $this->binlogMagic()
            . $this->binlogEvent(Constants::FORMAT_DESCRIPTION_EVENT, str_repeat("\x00", 50) . \chr(1))
            . $this->binlogEvent(Constants::TABLE_MAP_EVENT, $this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $columns))
            . $this->binlogEvent(Constants::WRITE_ROWS_EVENT_V2, $this->binlogRowsV2(self::TABLE_ID, 1, $this->binlogRow(1, $value)));

        $chunks = (function () use ($binlog): \Generator {
            foreach (str_split($binlog, 64) as $chunk) {
                yield $chunk;
            }
        })();

        $changes = $this->drain(new File($chunks));

        $this->assertCount(1, $changes);
        $this->assertSame($payload, $changes[0]->rows[0]['data']);
    }

    public function testRejectsAnImpossiblySmallEventSize(): void
    {
        // An event header whose event_size field claims 4 — smaller than the
        // 19-byte header itself. A corrupt archive must fail loudly, not frame
        // the malformed header as a valid (empty-body) event.
        $header = "\x00\x00\x00\x00" . \chr(Constants::FORMAT_DESCRIPTION_EVENT) . "\x00\x00\x00\x00"
            . pack('V', 4) . "\x00\x00\x00\x00" . "\x00\x00";

        $source = new File($this->binlogMagic() . $header);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Corrupt binlog');

        $source->open();
    }

    public function testCanBeReopenedAfterDraining(): void
    {
        $source = new File($this->insertBinlog(checksum: true));

        $source->open();
        iterator_to_array($source->events()); // drain to exhaustion

        // Re-opening the same instance must reset stream state, not stay exhausted.
        $source->open();
        $this->assertTrue($source->checksum());
        $this->assertNotEmpty(iterator_to_array($source->events()));
    }

    /**
     * A complete binlog with a single INSERT, checksummed or not.
     */
    private function insertBinlog(bool $checksum): string
    {
        $fde = $checksum ? str_repeat("\x00", 50) . \chr(1) : str_repeat("\x00", 51);
        $columns = [
            ['type' => Constants::TYPE_LONGLONG, 'name' => '_id'],
            ['type' => Constants::TYPE_VAR_STRING, 'meta' => pack('v', 100), 'name' => '_uid'],
        ];
        $rows = $this->binlogRowsV2(self::TABLE_ID, 2, $this->binlogRow(2, pack('P', 1), \chr(1) . 'x'));

        return $this->binlogMagic()
            . $this->binlogEvent(Constants::FORMAT_DESCRIPTION_EVENT, $fde, $checksum)
            . $this->binlogEvent(Constants::TABLE_MAP_EVENT, $this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $columns), $checksum)
            . $this->binlogEvent(Constants::WRITE_ROWS_EVENT_V2, $rows, $checksum);
    }
}
