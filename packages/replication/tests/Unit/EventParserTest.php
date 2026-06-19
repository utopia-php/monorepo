<?php

namespace Utopia\Replication\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Replication\Adapter\MySQL\Constants;
use Utopia\Replication\Adapter\MySQL\EventParser;

class EventParserTest extends TestCase
{
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
        $body .= \chr(Constants::METADATA_COLUMN_NAME) . pack('C', \strlen($names)) . $names;

        return $body;
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
        $body .= pack('C', \strlen($metadata)) . $metadata;
        $body .= "\x00"; // null bitmap, then no optional metadata

        return $body;
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
     * @return array<string, array{string, int, int}>
     */
    public static function signednessProvider(): array
    {
        // SIGNEDNESS bitmap: MSB set => UNSIGNED. One numeric column => bit 7.
        return [
            'signed -1'    => ["\x00", 0xFF, -1],
            'signed 127'   => ["\x00", 0x7F, 127],
            'unsigned 255' => ["\x80", 0xFF, 255],
        ];
    }
}
