<?php

namespace Utopia\Replication\Tests\Unit\Source\MySQL;

use Utopia\Replication\Source\MySQL\Constants;

/**
 * Hand-builds binlog bytes — events, TABLE_MAP, ROWS — for the pure decoder
 * tests, so they need neither a server nor recorded fixtures.
 *
 * @phpstan-type Column array{type: int, meta?: string, name: string}
 */
trait BinlogFixtures
{
    /** Binlog file magic: 0xFE then "bin". */
    private function binlogMagic(): string
    {
        return "\xfe\x62\x69\x6e";
    }

    /** Little-endian unsigned integer of $bytes width. */
    private function le(int $value, int $bytes): string
    {
        $out = '';
        for ($i = 0; $i < $bytes; $i++) {
            $out .= \chr(($value >> ($i * 8)) & 0xFF);
        }

        return $out;
    }

    /**
     * Wrap an event body in a 19-byte header (+ a dummy 4-byte CRC trailer when
     * checksummed). The event_size field is filled so a file reader can frame it.
     */
    private function binlogEvent(int $type, string $body, bool $checksum = true): string
    {
        $crc = $checksum ? "\xDE\xAD\xBE\xEF" : '';
        $eventSize = Constants::EVENT_HEADER_SIZE + \strlen($body) + \strlen($crc);

        $header = "\x00\x00\x00\x00"      // timestamp
            . \chr($type)                 // event type
            . "\x00\x00\x00\x00"          // server id
            . pack('V', $eventSize)       // event size
            . "\x00\x00\x00\x00"          // log position
            . "\x00\x00";                 // flags

        return $header . $body . $crc;
    }

    /** GTID_EVENT body: commit flag + 16-byte source UUID + 8-byte gno. */
    private function binlogGtidEvent(string $sidHex, int $gno): string
    {
        return "\x00" . hex2bin($sidHex) . pack('P', $gno);
    }

    /** QUERY_EVENT body carrying $query (e.g. "BEGIN" or a DDL statement). */
    private function binlogQueryEvent(string $query, string $schema = ''): string
    {
        return pack('V', 1)               // thread id
            . pack('V', 0)                // execution time
            . \chr(\strlen($schema))      // schema length
            . pack('v', 0)                // error code
            . pack('v', 0)                // status-variables length (none)
            . $schema . "\x00"            // schema name + NUL
            . $query;                     // the SQL statement
    }

    /**
     * TABLE_MAP body shipping FULL column-name metadata.
     *
     * @param list<Column> $columns each ['type' => int, 'meta' => rawMetaBytes, 'name' => string]
     */
    private function binlogTableMap(int $tableId, string $schema, string $table, array $columns, string $signedness = ''): string
    {
        $types = '';
        $meta = '';
        $names = '';
        foreach ($columns as $column) {
            $types .= \chr($column['type']);
            $meta .= $column['meta'] ?? '';
            $names .= \chr(\strlen($column['name'])) . $column['name'];
        }
        $count = \count($columns);

        $body = $this->le($tableId, 6) . "\x00\x00"
            . \chr(\strlen($schema)) . $schema . "\x00"
            . \chr(\strlen($table)) . $table . "\x00"
            . \chr($count) . $types
            . \chr(\strlen($meta)) . $meta
            . str_repeat("\x00", intdiv($count + 7, 8)); // null bitmap (all NOT NULL)

        if ($signedness !== '') {
            $body .= \chr(Constants::METADATA_SIGNEDNESS) . \chr(\strlen($signedness)) . $signedness;
        }

        return $body . (\chr(Constants::METADATA_COLUMN_NAME) . \chr(\strlen($names)) . $names);
    }

    /** WRITE_ROWS/DELETE_ROWS v2 body: one present bitmap then the rows. */
    private function binlogRowsV2(int $tableId, int $columnCount, string ...$rows): string
    {
        $present = $this->presentBitmap($columnCount, $columnCount);

        return $this->le($tableId, 6) . "\x00\x00" . "\x02\x00" . \chr($columnCount) . $present . implode('', $rows);
    }

    /** WRITE_ROWS v2 body with an explicit present bitmap (for partial-column rows). */
    private function binlogPartialRowsV2(int $tableId, int $columnCount, string $present, string ...$rows): string
    {
        return $this->le($tableId, 6) . "\x00\x00" . "\x02\x00" . \chr($columnCount) . $present . implode('', $rows);
    }

    /** UPDATE_ROWS v2 body: before + after present bitmaps, then (before, after) values. */
    private function binlogUpdateV2(int $tableId, int $columnCount, string ...$rows): string
    {
        $present = $this->presentBitmap($columnCount, $columnCount);

        return $this->le($tableId, 6) . "\x00\x00" . "\x02\x00" . \chr($columnCount) . $present . $present . implode('', $rows);
    }

    /** A row image: null bitmap (no nulls) over $presentCount columns, then the values. */
    private function binlogRow(int $presentCount, string ...$values): string
    {
        return str_repeat("\x00", intdiv($presentCount + 7, 8)) . implode('', $values);
    }

    /** Low $presentCount bits set within a $columnCount-wide bitmap. */
    private function presentBitmap(int $columnCount, int $presentCount): string
    {
        $out = '';
        $remaining = $presentCount;
        for ($i = 0, $bytes = intdiv($columnCount + 7, 8); $i < $bytes; $i++) {
            $bits = max(0, min(8, $remaining));
            $out .= \chr((1 << $bits) - 1);
            $remaining -= $bits;
        }

        return $out;
    }
}
