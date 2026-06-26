<?php

namespace Utopia\Replication\Source\MySQL;

use Utopia\Replication\Change;

/**
 * Turns a stream of raw binlog event records into {@see Change}s.
 *
 * Pure with respect to transport: it never touches a socket or a file, it only
 * decodes the event records a {@see Transport} hands it. GTID tracking lives
 * here too — the checkpoint advances on transaction commit (XID), so a crash
 * mid-transaction re-streams it (treat changes as idempotent).
 */
final class Decoder
{
    private const array ROWS_EVENTS = [
        Constants::WRITE_ROWS_EVENT_V1 => Change::INSERT,
        Constants::WRITE_ROWS_EVENT_V2 => Change::INSERT,
        Constants::UPDATE_ROWS_EVENT_V1 => Change::UPDATE,
        Constants::UPDATE_ROWS_EVENT_V2 => Change::UPDATE,
        Constants::DELETE_ROWS_EVENT_V1 => Change::DELETE,
        Constants::DELETE_ROWS_EVENT_V2 => Change::DELETE,
    ];

    private string $currentSid = '';
    private int $currentGno = 0;

    /**
     * @param GtidSet     $executed Running checkpoint, seeded from the source's
     *                              resume position and advanced on each commit.
     * @param string|null $schema   Only emit changes for this database; others
     *                              are decoded for bookkeeping but not returned.
     * @param bool        $checksum Whether records carry a 4-byte CRC32 trailer.
     */
    public function __construct(
        private readonly EventParser $parser,
        private readonly GtidSet $executed,
        private readonly ?string $schema = null,
        private readonly bool $checksum = false,
    ) {}

    /**
     * Decode one raw event record, returning a {@see Change} for ROWS events and
     * null for everything else (GTID/XID/TABLE_MAP are consumed for bookkeeping;
     * the rest — ROTATE, FORMAT_DESCRIPTION, QUERY, … — are ignored).
     */
    public function decode(string $event): ?Change
    {
        $eventType = \ord($event[4]); // event header: [0-3] timestamp, [4] type
        $body = substr($event, Constants::EVENT_HEADER_SIZE);
        if ($this->checksum) {
            $body = substr($body, 0, -4);
        }
        if ($eventType === Constants::GTID_EVENT) {
            $this->trackGtid($body);
            return null;
        }
        if ($eventType === Constants::QUERY_EVENT) {
            $this->commitIfStatement($body);
            return null;
        }
        if ($eventType === Constants::XID_EVENT) {
            $this->commit();
            return null;
        }
        if ($eventType === Constants::TABLE_MAP_EVENT) {
            $this->parser->parseTableMap($body);
            return null;
        }
        if (isset(self::ROWS_EVENTS[$eventType])) {
            return $this->buildChange($eventType, $body);
        }
        return null;
    }

    /**
     * Current executed-GTID-set — the resumable checkpoint after the last commit.
     */
    public function position(): string
    {
        return (string) $this->executed;
    }

    private function buildChange(int $eventType, string $body): ?Change
    {
        $decoded = $this->parser->parseRows($eventType, $body);
        if ($decoded === null) {
            return null;
        }

        if ($this->schema !== null && $decoded['schema'] !== $this->schema) {
            return null;
        }

        return new Change(
            action: self::ROWS_EVENTS[$eventType],
            database: $decoded['schema'],
            table: $decoded['table'],
            rows: $decoded['rows'],
            gtid: (string) $this->executed,
        );
    }

    /**
     * A QUERY_EVENT that is not the "BEGIN" of an explicit transaction is an
     * autocommitted statement — a DDL, or DML on a non-transactional engine —
     * which is its own complete transaction with no XID_EVENT, so it commits the
     * current GTID. A "BEGIN" merely opens a transaction whose XID_EVENT (or
     * COMMIT query) commits it. This keeps DDL checkpoints current without
     * advancing a row transaction that was cut off before its XID.
     */
    private function commitIfStatement(string $body): void
    {
        $reader = new BinaryReader($body);
        $reader->skip(8);                       // thread id + execution time
        $schemaLength = $reader->readUInt8();
        $reader->skip(2);                       // error code
        $reader->skip($reader->readUInt16());   // status-variables block
        $reader->skip($schemaLength + 1);       // schema name + NUL terminator
        $query = $reader->read($reader->remaining());

        if (strtoupper(trim($query)) !== 'BEGIN') {
            $this->commit();
        }
    }

    private function trackGtid(string $body): void
    {
        $reader = new BinaryReader($body);
        $reader->skip(1); // commit flag
        $this->currentSid = $this->formatUuid($reader->read(16));
        $this->currentGno = $reader->readUInt64();
    }

    /**
     * Mark the in-flight transaction committed. We only advance the checkpoint
     * on commit, so a crash mid-transaction re-streams it (purges are idempotent).
     */
    private function commit(): void
    {
        if ($this->currentSid !== '' && $this->currentGno > 0) {
            $this->executed->add($this->currentSid, $this->currentGno);
            $this->currentSid = '';
            $this->currentGno = 0;
        }
    }

    private function formatUuid(string $binary): string
    {
        $hex = bin2hex($binary);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
