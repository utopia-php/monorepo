<?php

namespace Utopia\Replication\Adapter\MySQL;

use Utopia\Replication\Exception;

/**
 * Decodes the binlog events we care about: TABLE_MAP (to learn a table's
 * column layout and names) and WRITE/UPDATE/DELETE_ROWS (the actual changes).
 *
 * With `binlog_row_metadata=FULL` column names ride in the TABLE_MAP optional
 * metadata. With MINIMAL (the MySQL default, and what some managed providers are
 * fixed to) they are absent; an optional resolver recovers them from
 * INFORMATION_SCHEMA, at most once per table.
 *
 * Pure (operates on byte buffers) when no resolver is supplied, so it is
 * unit-testable with fixtures.
 */
class EventParser
{
    /**
     * table_id => decoded table definition.
     *
     * @var array<int, array{schema: string, table: string, count: int, types: list<int>, metadata: list<int>, names: list<string>, signed: list<bool>}>
     */
    private array $tables = [];

    /**
     * "schema.table" => resolved column names, so we hit INFORMATION_SCHEMA at
     * most once per table rather than on every (per-transaction) TABLE_MAP.
     *
     * @var array<string, array{count: int, names: list<string>}>
     */
    private array $resolvedNames = [];

    private const array DIGITS_TO_BYTES = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];

    /**
     * @param (\Closure(string, string): list<string>)|null $columnResolver
     *        Resolves a (schema, table) to ordinal-ordered column names when the
     *        binlog omits them (MINIMAL metadata). Omit for pure FULL-only use.
     */
    public function __construct(
        private readonly ?\Closure $columnResolver = null,
    ) {}

    /**
     * Cache a table definition from a TABLE_MAP event body.
     */
    public function parseTableMap(string $body): void
    {
        $reader = new BinaryReader($body);

        $tableId = $reader->readUInt(6);
        $reader->skip(2); // flags

        $schema = $reader->read($reader->readUInt8());
        $reader->skip(1); // NUL
        $table = $reader->read($reader->readUInt8());
        $reader->skip(1); // NUL

        $count = $reader->readLengthEncodedInt() ?? 0;
        $types = array_values(unpack('C*', $reader->read($count)) ?: []);

        $metadataBlock = $reader->readLengthEncodedString() ?? '';
        $metadata = $this->parseMetadata($types, $metadataBlock);

        $reader->skip((int) ceil($count / 8)); // null bitmap

        [$names, $signedness] = $this->parseOptionalMetadata($reader);
        if ($names === []) {
            $names = $this->resolveNames($schema, $table, $count);
        }
        $signed = $this->computeSignedness($types, $signedness);

        $this->tables[$tableId] = compact('schema', 'table', 'count', 'types', 'metadata', 'names', 'signed');
    }

    /**
     * Resolve column names for a MINIMAL-metadata table. Uses the injected
     * resolver when its arity matches the binlog's column count, otherwise
     * positional names. Either way the outcome is cached, so the resolver runs at
     * most once per table — a failing resolver doesn't re-fire on every (per-
     * transaction) TABLE_MAP.
     *
     * @return list<string>
     */
    private function resolveNames(string $schema, string $table, int $count): array
    {
        $key = $schema . '.' . $table;
        $cached = $this->resolvedNames[$key] ?? null;
        if ($cached !== null && $cached['count'] === $count) {
            return $cached['names'];
        }

        $names = [];
        if ($this->columnResolver !== null) {
            $resolved = ($this->columnResolver)($schema, $table);
            if (\count($resolved) === $count) {
                $names = $resolved;
            }
        }
        if ($names === []) {
            $names = array_map('strval', range(0, max(0, $count - 1)));
        }

        $this->resolvedNames[$key] = ['count' => $count, 'names' => $names];

        return $names;
    }

    /**
     * Numeric column types that consume a bit in the SIGNEDNESS metadata bitmap
     * (matches MySQL's notion of numeric fields). Bit alignment must count all of
     * these, even though two's-complement is only applied to integer types.
     */
    private const array NUMERIC_TYPES = [
        Constants::TYPE_TINY,
        Constants::TYPE_SHORT,
        Constants::TYPE_INT24,
        Constants::TYPE_LONG,
        Constants::TYPE_LONGLONG,
        Constants::TYPE_YEAR,
        Constants::TYPE_FLOAT,
        Constants::TYPE_DOUBLE,
        Constants::TYPE_NEWDECIMAL,
        Constants::TYPE_DECIMAL,
    ];

    /**
     * Decode a ROWS event body into row maps.
     *
     * @return array{schema: string, table: string, rows: list<array<string, mixed>>}|null
     */
    public function parseRows(int $eventType, string $body): ?array
    {
        $reader = new BinaryReader($body);

        $tableId = $reader->readUInt(6);
        $reader->skip(2); // flags

        $table = $this->tables[$tableId] ?? null;
        if ($table === null) {
            return null; // TABLE_MAP not seen (e.g. mid-stream start) — skip
        }

        $isV2 = \in_array($eventType, [
            Constants::WRITE_ROWS_EVENT_V2,
            Constants::UPDATE_ROWS_EVENT_V2,
            Constants::DELETE_ROWS_EVENT_V2,
        ], true);
        if ($isV2) {
            $extraLength = $reader->readUInt16();
            $reader->skip($extraLength - 2);
        }

        $columnCount = $reader->readLengthEncodedInt() ?? 0;
        $bitmapSize = (int) ceil($columnCount / 8);

        $present = $reader->read($bitmapSize);
        $isUpdate = \in_array($eventType, [Constants::UPDATE_ROWS_EVENT_V1, Constants::UPDATE_ROWS_EVENT_V2], true);
        $presentAfter = $isUpdate ? $reader->read($bitmapSize) : $present;

        $rows = [];
        while (!$reader->eof()) {
            if ($isUpdate) {
                $this->readRow($reader, $table, $present); // before-image (discarded)
            }
            $rows[] = $this->readRow($reader, $table, $presentAfter);
        }

        return ['schema' => $table['schema'], 'table' => $table['table'], 'rows' => $rows];
    }

    /**
     * @param array{count: int, types: list<int>, metadata: list<int>, names: list<string>, signed: list<bool>} $table
     * @return array<string, mixed>
     */
    private function readRow(BinaryReader $reader, array $table, string $present): array
    {
        $presentCount = $this->countBits($present);
        $nullBitmap = $reader->read((int) ceil($presentCount / 8));

        $row = [];
        $nullIndex = 0;
        for ($column = 0; $column < $table['count']; $column++) {
            if (!$this->bitAt($present, $column)) {
                continue;
            }

            $name = $table['names'][$column] ?? (string) $column;
            $isNull = $this->bitAt($nullBitmap, $nullIndex);
            $nullIndex++;

            $row[$name] = $isNull
                ? null
                : $this->decodeValue($reader, $table['types'][$column], $table['metadata'][$column], $table['signed'][$column] ?? false);
        }

        return $row;
    }

    private function decodeValue(BinaryReader $reader, int $type, int $metadata, bool $signed): mixed
    {
        switch ($type) {
            case Constants::TYPE_TINY:
                return $this->maybeSigned($reader->readUInt(1), 1, $signed);
            case Constants::TYPE_SHORT:
                return $this->maybeSigned($reader->readUInt(2), 2, $signed);
            case Constants::TYPE_INT24:
                return $this->maybeSigned($reader->readUInt(3), 3, $signed);
            case Constants::TYPE_LONG:
                return $this->maybeSigned($reader->readUInt(4), 4, $signed);
            case Constants::TYPE_LONGLONG:
                // 64-bit reads already wrap to PHP's signed int; unsigned values
                // above PHP_INT_MAX cannot be represented and are left as-is.
                return $reader->readUInt(8);
            case Constants::TYPE_YEAR:
                return $reader->readUInt(1);
            case Constants::TYPE_FLOAT:
                return $this->unpackNumber('g', $reader->read(4));
            case Constants::TYPE_DOUBLE:
                return $this->unpackNumber('e', $reader->read(8));
            case Constants::TYPE_VARCHAR:
            case Constants::TYPE_VAR_STRING:
                $prefix = $metadata > 255 ? 2 : 1;
                return $reader->read($reader->readUInt($prefix));
            case Constants::TYPE_STRING:
                return $this->decodeString($reader, $metadata);
            case Constants::TYPE_BLOB:
            case Constants::TYPE_TINY_BLOB:
            case Constants::TYPE_MEDIUM_BLOB:
            case Constants::TYPE_LONG_BLOB:
            case Constants::TYPE_GEOMETRY:
            case Constants::TYPE_JSON:
                return $reader->read($reader->readUInt(max(1, $metadata)));
            case Constants::TYPE_ENUM:
            case Constants::TYPE_SET:
                return $reader->readUInt(max(1, $metadata));
            case Constants::TYPE_NEWDECIMAL:
            case Constants::TYPE_DECIMAL:
                return $reader->read($this->decimalLength($metadata >> 8, $metadata & 0xFF));
            case Constants::TYPE_DATE:
            case Constants::TYPE_TIME:
                return $reader->read(3);
            case Constants::TYPE_TIMESTAMP:
                return $reader->read(4);
            case Constants::TYPE_DATETIME:
                return $reader->read(8);
            case Constants::TYPE_TIMESTAMP2:
                return $reader->read(4 + intdiv($metadata + 1, 2));
            case Constants::TYPE_DATETIME2:
                return $reader->read(5 + intdiv($metadata + 1, 2));
            case Constants::TYPE_TIME2:
                return $reader->read(3 + intdiv($metadata + 1, 2));
            case Constants::TYPE_BIT:
                $bits = (($metadata >> 8) * 8) + ($metadata & 0xFF);
                return $reader->read((int) ceil($bits / 8));
            case Constants::TYPE_NULL:
                return null;
            default:
                throw new Exception("Unsupported binlog column type: {$type}");
        }
    }

    private function unpackNumber(string $format, string $bytes): float
    {
        $value = unpack($format, $bytes);

        return \is_array($value) ? (float) ($value[1] ?? 0) : 0.0;
    }

    /**
     * Reinterpret an unsigned little-endian integer as two's-complement signed
     * when the column is signed. Only used for widths < 64 bits — 64-bit reads
     * already wrap to PHP's native signed int.
     */
    private function maybeSigned(int $value, int $bytes, bool $signed): int
    {
        if (!$signed) {
            return $value;
        }

        $signBit = 1 << ($bytes * 8 - 1);

        return $value >= $signBit ? $value - ($signBit << 1) : $value;
    }

    private function decodeString(BinaryReader $reader, int $metadata): mixed
    {
        $realType = $metadata >> 8;
        $low = $metadata & 0xFF;

        if ($realType === Constants::TYPE_ENUM || $realType === Constants::TYPE_SET) {
            return $reader->readUInt(max(1, $low));
        }

        // Packed CHAR length: high bits live in the real-type byte.
        $maxLength = ((($realType & 0x30) ^ 0x30) << 4) | $low;
        $prefix = $maxLength > 255 ? 2 : 1;

        return $reader->read($reader->readUInt($prefix));
    }

    /**
     * @param list<int> $types
     * @return list<int>
     */
    private function parseMetadata(array $types, string $block): array
    {
        $reader = new BinaryReader($block);
        $metadata = [];

        foreach ($types as $type) {
            $metadata[] = match ($type) {
                Constants::TYPE_FLOAT,
                Constants::TYPE_DOUBLE,
                Constants::TYPE_BLOB,
                Constants::TYPE_TINY_BLOB,
                Constants::TYPE_MEDIUM_BLOB,
                Constants::TYPE_LONG_BLOB,
                Constants::TYPE_GEOMETRY,
                Constants::TYPE_JSON,
                Constants::TYPE_TIMESTAMP2,
                Constants::TYPE_DATETIME2,
                Constants::TYPE_TIME2 => $reader->readUInt8(),
                Constants::TYPE_VARCHAR,
                Constants::TYPE_VAR_STRING,
                Constants::TYPE_BIT => $reader->readUInt16(),
                Constants::TYPE_NEWDECIMAL,
                Constants::TYPE_DECIMAL => ($reader->readUInt8() << 8) | $reader->readUInt8(),
                Constants::TYPE_STRING,
                Constants::TYPE_ENUM,
                Constants::TYPE_SET => ($reader->readUInt8() << 8) | $reader->readUInt8(),
                default => 0,
            };
        }

        return $metadata;
    }

    /**
     * Read the TABLE_MAP optional metadata, returning column names and the raw
     * SIGNEDNESS bitmap (both require binlog_row_metadata=FULL).
     *
     * @return array{0: list<string>, 1: string}
     */
    private function parseOptionalMetadata(BinaryReader $reader): array
    {
        $names = [];
        $signedness = '';

        while (!$reader->eof()) {
            $fieldType = $reader->readUInt8();
            $fieldLength = $reader->readLengthEncodedInt() ?? 0;
            $field = $reader->read($fieldLength);

            if ($fieldType === Constants::METADATA_COLUMN_NAME) {
                $fieldReader = new BinaryReader($field);
                while (!$fieldReader->eof()) {
                    $names[] = $fieldReader->readLengthEncodedString() ?? '';
                }
            } elseif ($fieldType === Constants::METADATA_SIGNEDNESS) {
                $signedness = $field;
            }
        }

        return [$names, $signedness];
    }

    /**
     * Map the SIGNEDNESS bitmap onto column indices. The bitmap covers only
     * numeric columns, MSB-first, where a set bit means UNSIGNED. Absent
     * metadata leaves every column unsigned (the safe legacy default).
     *
     * @param list<int> $types
     * @return list<bool>
     */
    private function computeSignedness(array $types, string $bitmap): array
    {
        $signed = [];
        $bit = 0;

        foreach ($types as $type) {
            if ($bitmap !== '' && \in_array($type, self::NUMERIC_TYPES, true)) {
                $byte = \ord($bitmap[$bit >> 3] ?? "\0");
                $unsigned = ($byte & (0x80 >> ($bit & 7))) !== 0;
                $signed[] = !$unsigned;
                $bit++;
            } else {
                $signed[] = false;
            }
        }

        return $signed;
    }

    private function decimalLength(int $precision, int $scale): int
    {
        $integer = $precision - $scale;
        $integerFull = intdiv($integer, 9);
        $fractionFull = intdiv($scale, 9);

        return $integerFull * 4 + self::DIGITS_TO_BYTES[$integer - $integerFull * 9]
            + $fractionFull * 4 + self::DIGITS_TO_BYTES[$scale - $fractionFull * 9];
    }

    private function bitAt(string $bitmap, int $index): bool
    {
        return (\ord($bitmap[$index >> 3]) >> ($index & 7) & 1) === 1;
    }

    private function countBits(string $bitmap): int
    {
        $count = 0;
        for ($i = 0, $len = \strlen($bitmap); $i < $len; $i++) {
            $count += substr_count(decbin(\ord($bitmap[$i])), '1');
        }

        return $count;
    }
}
