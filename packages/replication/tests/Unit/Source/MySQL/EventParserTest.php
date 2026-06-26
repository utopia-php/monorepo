<?php

declare(strict_types=1);

namespace Utopia\Replication\Tests\Unit\Source\MySQL;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Replication\Exception;
use Utopia\Replication\Source\MySQL\Constants;
use Utopia\Replication\Source\MySQL\EventParser;

final class EventParserTest extends TestCase
{
    use BinlogFixtures;

    private const int TABLE_ID = 42;
    private const string SCHEMA = 'appwrite';
    private const string TABLE = 'console15x_projects';

    /**
     * A two-column table: `_id` BIGINT, `_uid` VARCHAR (utf8mb4(255) => 1020 max bytes).
     */
    private function tableMapBody(): string
    {
        $body = $this->uint(self::TABLE_ID, 6)
            . "\x00\x00" // flags
            . \chr(\strlen(self::SCHEMA)) . self::SCHEMA . "\x00"
            . \chr(\strlen(self::TABLE)) . self::TABLE . "\x00"
            . \chr(2) // column count
            . \chr(Constants::TYPE_LONGLONG) . \chr(Constants::TYPE_VAR_STRING);

        $metadata = pack('v', 1020); // VAR_STRING max length; LONGLONG has none
        $body .= pack('C', \strlen($metadata)) . $metadata;
        $body .= "\x00"; // null bitmap (ceil(2/8))

        // Optional metadata: SIGNEDNESS (skipped) then COLUMN_NAME.
        $body .= \chr(1) . \chr(1) . "\x00"; // SIGNEDNESS field, 1 byte payload
        $names = \chr(3) . '_id' . \chr(4) . '_uid';

        return $body . (\chr(Constants::METADATA_COLUMN_NAME) . pack('C', \strlen($names)) . $names);
    }

    /**
     * Same table as {@see tableMapBody()} but with no optional metadata at all —
     * i.e. binlog_row_metadata=MINIMAL, so column names are absent.
     */
    private function tableMapBodyMinimal(): string
    {
        $body = $this->uint(self::TABLE_ID, 6)
            . "\x00\x00"
            . \chr(\strlen(self::SCHEMA)) . self::SCHEMA . "\x00"
            . \chr(\strlen(self::TABLE)) . self::TABLE . "\x00"
            . \chr(2)
            . \chr(Constants::TYPE_LONGLONG) . \chr(Constants::TYPE_VAR_STRING);

        $metadata = pack('v', 1020);
        $body .= pack('C', \strlen($metadata)) . $metadata; // null bitmap, then no optional metadata

        return $body . "\x00";
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
        // null bitmap (no nulls) + BIGINT + length-prefixed VARCHAR (2-byte prefix).
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

    public function testWriteRowsDecodesNamedColumns(): void
    {
        $parser = new EventParser();
        $parser->parseTableMap($this->tableMapBody());

        $body = $this->rowsHeader() . $this->cell(100, 'proj123');
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $body);

        $this->assertNotNull($decoded);

        $this->assertSame(self::SCHEMA, $decoded['schema']);
        $this->assertSame(self::TABLE, $decoded['table']);
        $this->assertCount(1, $decoded['rows']);
        $this->assertSame(100, $decoded['rows'][0]['_id']);
        $this->assertSame('proj123', $decoded['rows'][0]['_uid']);
    }

    public function testMultipleRowsInOneEvent(): void
    {
        $parser = new EventParser();
        $parser->parseTableMap($this->tableMapBody());

        $body = $this->rowsHeader() . $this->cell(1, 'aaa') . $this->cell(2, 'bbbb');
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $body);

        $this->assertNotNull($decoded);

        $this->assertCount(2, $decoded['rows']);
        $this->assertSame('aaa', $decoded['rows'][0]['_uid']);
        $this->assertSame('bbbb', $decoded['rows'][1]['_uid']);
    }

    public function testUpdateKeepsAfterImage(): void
    {
        $parser = new EventParser();
        $parser->parseTableMap($this->tableMapBody());

        // Update header carries two "columns present" bitmaps (before + after).
        $header = $this->uint(self::TABLE_ID, 6) . "\x00\x00" . "\x02\x00" . \chr(2) . \chr(0b11) . \chr(0b11);
        $body = $header . $this->cell(100, 'old_uid') . $this->cell(100, 'new_uid');

        $decoded = $parser->parseRows(Constants::UPDATE_ROWS_EVENT_V2, $body);

        $this->assertNotNull($decoded);

        $this->assertCount(1, $decoded['rows']);
        $this->assertSame('new_uid', $decoded['rows'][0]['_uid']);
    }

    public function testNullColumnIsDecodedAsNull(): void
    {
        $parser = new EventParser();
        $parser->parseTableMap($this->tableMapBody());

        // null bitmap marks column 1 (_uid) as null; only _id has a value.
        $row = \chr(0b10) . pack('P', 7);
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->rowsHeader() . $row);

        $this->assertNotNull($decoded);

        $this->assertSame(7, $decoded['rows'][0]['_id']);
        $this->assertNull($decoded['rows'][0]['_uid']);
    }

    public function testUnknownTableIsSkipped(): void
    {
        $parser = new EventParser();
        // No TABLE_MAP cached for this id.
        $body = $this->rowsHeader() . $this->cell(1, 'x');

        $this->assertNull($parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $body));
    }

    public function testMinimalMetadataResolvesNamesViaResolver(): void
    {
        $calls = [];
        $parser = new EventParser(function (string $schema, string $table) use (&$calls): array {
            $calls[] = "{$schema}.{$table}";

            return ['_id', '_uid'];
        });
        $parser->parseTableMap($this->tableMapBodyMinimal());

        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->rowsHeader() . $this->cell(100, 'proj123'));

        $this->assertNotNull($decoded);
        $this->assertSame(100, $decoded['rows'][0]['_id']);
        $this->assertSame('proj123', $decoded['rows'][0]['_uid']);

        // A second TABLE_MAP for the same table must reuse the cached names.
        $parser->parseTableMap($this->tableMapBodyMinimal());
        $this->assertSame(['appwrite.console15x_projects'], $calls);
    }

    public function testMinimalMetadataFallsBackToPositionalNamesWithoutResolver(): void
    {
        $parser = new EventParser();
        $parser->parseTableMap($this->tableMapBodyMinimal());

        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->rowsHeader() . $this->cell(100, 'proj123'));

        $this->assertNotNull($decoded);
        $this->assertSame(100, $decoded['rows'][0][0] ?? null);
        $this->assertSame('proj123', $decoded['rows'][0][1] ?? null);
    }

    public function testResolverArityMismatchFallsBackToPositional(): void
    {
        $calls = 0;
        $parser = new EventParser(function (string $schema, string $table) use (&$calls): array {
            $calls++;

            return ['only_one']; // table has 2 columns -> mismatch
        });
        $parser->parseTableMap($this->tableMapBodyMinimal());

        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->rowsHeader() . $this->cell(5, 'x'));

        $this->assertNotNull($decoded);
        $this->assertSame(5, $decoded['rows'][0][0] ?? null);
        $this->assertSame('x', $decoded['rows'][0][1] ?? null);

        // The positional fallback is cached too, so a repeat TABLE_MAP does not
        // re-invoke the (failing) resolver.
        $parser->parseTableMap($this->tableMapBodyMinimal());
        $this->assertSame(1, $calls);
    }

    #[DataProvider('signednessProvider')]
    public function testSignedIntegerDecoding(string $signednessByte, int $rawByte, int $expected): void
    {
        // Single TINYINT column 'n' with an explicit SIGNEDNESS metadata byte.
        $tableMap = $this->uint(self::TABLE_ID, 6) . "\x00\x00"
            . \chr(\strlen(self::SCHEMA)) . self::SCHEMA . "\x00"
            . \chr(\strlen(self::TABLE)) . self::TABLE . "\x00"
            . \chr(1)                                    // column count
            . \chr(Constants::TYPE_TINY)                 // types
            . \chr(0)                                    // metadata block (TINY has none)
            . "\x00"                                     // null bitmap
            . \chr(Constants::METADATA_SIGNEDNESS) . \chr(1) . $signednessByte
            . \chr(Constants::METADATA_COLUMN_NAME) . \chr(2) . \chr(1) . 'n';

        $parser = new EventParser();
        $parser->parseTableMap($tableMap);

        $body = $this->uint(self::TABLE_ID, 6) . "\x00\x00" . "\x02\x00"
            . \chr(1) . \chr(0b1)   // column count + present bitmap
            . "\x00"                // null bitmap
            . pack('C', $rawByte);  // TINY value

        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $body);
        $this->assertNotNull($decoded);
        $this->assertSame($expected, $decoded['rows'][0]['n']);
    }

    /**
     * @return \Iterator<string, array{string, int, int}>
     */
    public static function signednessProvider(): \Iterator
    {
        // SIGNEDNESS bitmap: MSB set => UNSIGNED. One numeric column => bit 7.
        yield 'signed -1' => ["\x00", 0xFF, -1];
        yield 'signed 127' => ["\x00", 0x7F, 127];
        yield 'unsigned 255' => ["\x80", 0xFF, 255];
    }

    /**
     * Decode one column of $type (with $meta TABLE_MAP metadata) carrying $value,
     * asserting both the decoded result and that a trailing sentinel column still
     * lands on its byte — i.e. the column consumed exactly the right width.
     */
    #[DataProvider('columnTypeProvider')]
    public function testDecodesColumnType(int $type, string $meta, string $value, mixed $expected): void
    {
        $columns = [
            ['type' => $type, 'meta' => $meta, 'name' => 'v'],
            ['type' => Constants::TYPE_TINY, 'name' => 'sentinel'],
        ];
        $parser = new EventParser();
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $columns));

        $body = $this->binlogRowsV2(self::TABLE_ID, 2, $this->binlogRow(2, $value, "\x7F"));
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $body);

        $this->assertNotNull($decoded);
        $this->assertSame($expected, $decoded['rows'][0]['v']);
        $this->assertSame(127, $decoded['rows'][0]['sentinel'], 'trailing column misaligned — wrong byte width consumed');
    }

    /**
     * @return \Iterator<string, array{int, string, string, mixed}>
     */
    public static function columnTypeProvider(): \Iterator
    {
        // Integers (unsigned, no SIGNEDNESS metadata).
        yield 'TINY' => [Constants::TYPE_TINY, '', \chr(200), 200];
        yield 'SHORT' => [Constants::TYPE_SHORT, '', pack('v', 60000), 60000];
        yield 'INT24' => [Constants::TYPE_INT24, '', "\x40\x42\x0F", 1000000];
        yield 'LONG' => [Constants::TYPE_LONG, '', pack('V', 3000000000), 3000000000];
        yield 'LONGLONG' => [Constants::TYPE_LONGLONG, '', pack('P', 9000000000), 9000000000];
        yield 'YEAR' => [Constants::TYPE_YEAR, '', \chr(123), 123];
        // Strings: 1-byte length prefix when max <= 255, else 2-byte.
        yield 'VARCHAR short' => [Constants::TYPE_VAR_STRING, pack('v', 100), \chr(3) . 'abc', 'abc'];
        yield 'VARCHAR long' => [Constants::TYPE_VAR_STRING, pack('v', 300), pack('v', 3) . 'xyz', 'xyz'];
        // BLOB: metadata = number of length bytes (here 2).
        yield 'BLOB' => [Constants::TYPE_BLOB, \chr(2), pack('v', 4) . 'blob', 'blob'];
        // ENUM index: metadata low byte = storage width.
        yield 'ENUM' => [Constants::TYPE_ENUM, "\x00\x01", \chr(2), 2];
        // Fixed-width temporals are returned as their raw bytes.
        yield 'TIMESTAMP' => [Constants::TYPE_TIMESTAMP, '', "\x01\x02\x03\x04", "\x01\x02\x03\x04"];
        yield 'DATETIME' => [Constants::TYPE_DATETIME, '', "\x01\x02\x03\x04\x05\x06\x07\x08", "\x01\x02\x03\x04\x05\x06\x07\x08"];
        yield 'DATE' => [Constants::TYPE_DATE, '', "\xAA\xBB\xCC", "\xAA\xBB\xCC"];
        // Fractional temporals: width grows with the fsp metadata byte.
        yield 'TIMESTAMP2(6)' => [Constants::TYPE_TIMESTAMP2, \chr(6), "\x01\x02\x03\x04\x05\x06\x07", "\x01\x02\x03\x04\x05\x06\x07"];
        yield 'DATETIME2(0)' => [Constants::TYPE_DATETIME2, \chr(0), "\x01\x02\x03\x04\x05", "\x01\x02\x03\x04\x05"];
        yield 'TIME2(0)' => [Constants::TYPE_TIME2, \chr(0), "\xAA\xBB\xCC", "\xAA\xBB\xCC"];
        // BIT: metadata packs (bytes, bits) -> 10 bits = ceil(10/8) = 2 bytes.
        yield 'BIT(10)' => [Constants::TYPE_BIT, pack('v', (1 << 8) | 2), "\x03\xFF", "\x03\xFF"];
        // NEWDECIMAL(5,2): precision/scale metadata -> 3 storage bytes.
        yield 'NEWDECIMAL(5,2)' => [Constants::TYPE_NEWDECIMAL, \chr(5) . \chr(2), "\x80\x00\x05", "\x80\x00\x05"];
    }

    public function testDecodesFloatAndDouble(): void
    {
        $columns = [
            ['type' => Constants::TYPE_FLOAT, 'meta' => \chr(4), 'name' => 'f'],
            ['type' => Constants::TYPE_DOUBLE, 'meta' => \chr(8), 'name' => 'd'],
        ];
        $parser = new EventParser();
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $columns));

        $body = $this->binlogRowsV2(self::TABLE_ID, 2, $this->binlogRow(2, pack('g', 1.5), pack('e', 2.5)));
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $body);

        $this->assertNotNull($decoded);
        $this->assertEqualsWithDelta(1.5, $decoded['rows'][0]['f'], 0.0001);
        $this->assertEqualsWithDelta(2.5, $decoded['rows'][0]['d'], 0.0001);
    }

    public function testDeleteRowsDecodesTheRemovedImage(): void
    {
        $columns = [
            ['type' => Constants::TYPE_LONGLONG, 'name' => '_id'],
            ['type' => Constants::TYPE_VAR_STRING, 'meta' => pack('v', 100), 'name' => '_uid'],
        ];
        $parser = new EventParser();
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $columns));

        $row = $this->binlogRow(2, pack('P', 7), \chr(4) . 'gone');
        $decoded = $parser->parseRows(Constants::DELETE_ROWS_EVENT_V2, $this->binlogRowsV2(self::TABLE_ID, 2, $row));

        $this->assertNotNull($decoded);
        $this->assertSame(7, $decoded['rows'][0]['_id']);
        $this->assertSame('gone', $decoded['rows'][0]['_uid']);
    }

    public function testPartialColumnPresenceOnlyDecodesPresentColumns(): void
    {
        $columns = [
            ['type' => Constants::TYPE_LONGLONG, 'name' => 'a'],
            ['type' => Constants::TYPE_LONGLONG, 'name' => 'b'],
            ['type' => Constants::TYPE_LONGLONG, 'name' => 'c'],
        ];
        $parser = new EventParser();
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, $columns));

        // present bitmap 0b101 -> only columns a and c are in the image.
        $row = $this->binlogRow(2, pack('P', 10), pack('P', 30));
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->binlogPartialRowsV2(self::TABLE_ID, 3, \chr(0b101), $row));

        $this->assertNotNull($decoded);
        $this->assertSame(['a' => 10, 'c' => 30], $decoded['rows'][0]);
        $this->assertArrayNotHasKey('b', $decoded['rows'][0]);
    }

    public function testDistinctTablesAreTrackedByTableId(): void
    {
        $parser = new EventParser();
        $parser->parseTableMap($this->binlogTableMap(1, 'appwrite', 'projects', [['type' => Constants::TYPE_LONGLONG, 'name' => 'id']]));
        $parser->parseTableMap($this->binlogTableMap(2, 'appwrite', 'users', [['type' => Constants::TYPE_LONGLONG, 'name' => 'id']]));

        $projects = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->binlogRowsV2(1, 1, $this->binlogRow(1, pack('P', 1))));
        $users = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->binlogRowsV2(2, 1, $this->binlogRow(1, pack('P', 2))));

        $this->assertNotNull($projects);
        $this->assertNotNull($users);
        $this->assertSame('projects', $projects['table']);
        $this->assertSame('users', $users['table']);
    }

    public function testReprocessingTableMapPicksUpSchemaChanges(): void
    {
        // A DDL mid-stream re-emits TABLE_MAP for the same id with a new layout;
        // the parser must decode subsequent rows against the latest definition.
        $parser = new EventParser();
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, [
            ['type' => Constants::TYPE_LONGLONG, 'name' => 'id'],
        ]));
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, [
            ['type' => Constants::TYPE_LONGLONG, 'name' => 'id'],
            ['type' => Constants::TYPE_VAR_STRING, 'meta' => pack('v', 100), 'name' => 'added'],
        ]));

        $row = $this->binlogRow(2, pack('P', 1), \chr(3) . 'new');
        $decoded = $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->binlogRowsV2(self::TABLE_ID, 2, $row));

        $this->assertNotNull($decoded);
        $this->assertSame(['id' => 1, 'added' => 'new'], $decoded['rows'][0]);
    }

    public function testUnsupportedColumnTypeThrows(): void
    {
        $parser = new EventParser();
        // 99 is not a MYSQL_TYPE_* the decoder knows.
        $parser->parseTableMap($this->binlogTableMap(self::TABLE_ID, self::SCHEMA, self::TABLE, [['type' => 99, 'name' => 'weird']]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported binlog column type');

        $parser->parseRows(Constants::WRITE_ROWS_EVENT_V2, $this->binlogRowsV2(self::TABLE_ID, 1, $this->binlogRow(1, "\x00")));
    }
}
