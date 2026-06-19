<?php

namespace Utopia\Replication\Adapter;

use Utopia\Replication\Adapter;
use Utopia\Replication\Adapter\MySQL\BinaryReader;
use Utopia\Replication\Adapter\MySQL\Connection;
use Utopia\Replication\Adapter\MySQL\Constants;
use Utopia\Replication\Adapter\MySQL\EventParser;
use Utopia\Replication\Adapter\MySQL\GtidSet;
use Utopia\Replication\Change;

/**
 * Streams ROW-format changes from a MySQL binlog over the replication protocol.
 *
 * Point it at a (group-replicated) MySQL and react to data changes — e.g. purge
 * a stale cache — without any application-level cross-region messaging.
 *
 * Requirements on the source server:
 *  - `binlog_format = ROW`
 *  - `gtid_mode = ON`
 *  - a user with REPLICATION SLAVE (and REPLICATION CLIENT) privileges
 *
 * Column names come from the TABLE_MAP when `binlog_row_metadata = FULL`;
 * otherwise (MINIMAL) they are resolved from INFORMATION_SCHEMA over a second
 * connection, so the reader works against default-config and managed MySQL too.
 *
 * Usage:
 *  $replication = new MySQL($host, $port, $user, $pass, $serverId, schema: 'appwrite');
 *  $replication->start($checkpoint);
 *  foreach ($replication->getChanges() as $change) { ...; $checkpoint = $change->gtid; }
 */
class MySQL implements Adapter
{
    private const array ROWS_EVENTS = [
        Constants::WRITE_ROWS_EVENT_V1 => Change::INSERT,
        Constants::WRITE_ROWS_EVENT_V2 => Change::INSERT,
        Constants::UPDATE_ROWS_EVENT_V1 => Change::UPDATE,
        Constants::UPDATE_ROWS_EVENT_V2 => Change::UPDATE,
        Constants::DELETE_ROWS_EVENT_V1 => Change::DELETE,
        Constants::DELETE_ROWS_EVENT_V2 => Change::DELETE,
    ];

    private Connection $connection;
    private ?Connection $schemaConnection = null;
    private EventParser $parser;
    private GtidSet $executed;
    private bool $checksum = false;

    private string $currentSid = '';
    private int $currentGno = 0;

    /**
     * @param string|null $schema Only emit changes for this database; others are
     *                            decoded for bookkeeping but not yielded.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly int $serverId,
        private readonly ?string $schema = null,
        private readonly bool $ssl = false,
        private readonly bool $sslVerify = true,
        private readonly string $sslCa = '',
    ) {
        $this->parser = new EventParser(fn(string $schema, string $table): array => $this->resolveColumns($schema, $table));
        $this->executed = new GtidSet();
    }

    /**
     * Connect, negotiate, and begin dumping the binlog.
     *
     * @param string|null $position Executed-GTID-set to resume from. When null,
     *                              starts from the server's current position
     *                              (only new changes).
     */
    public function start(?string $position = null): void
    {
        $this->connection = new Connection($this->host, $this->port, $this->username, $this->password, $this->ssl, $this->sslVerify, $this->sslCa);
        $this->connection->connect();

        $this->connection->execute('SET @master_binlog_checksum = @@global.binlog_checksum');
        $checksum = $this->connection->queryScalar('SELECT @@global.binlog_checksum') ?? 'NONE';
        $this->checksum = strtoupper(trim($checksum)) !== 'NONE';

        $this->registerSlave();

        $gtid = ($position !== null && $position !== '')
            ? $position
            : ($this->connection->queryScalar('SELECT @@global.gtid_executed') ?? '');
        $this->executed = new GtidSet($gtid);

        $this->sendDumpCommand();
    }

    public function stop(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        if ($this->schemaConnection !== null) {
            $this->schemaConnection->close();
            $this->schemaConnection = null;
        }
    }

    /**
     * Resolve a table's column names in ordinal order from INFORMATION_SCHEMA,
     * over a second connection (the dump connection is busy streaming). This is
     * what lets the reader cope with `binlog_row_metadata = MINIMAL`. Returns an
     * empty list on any failure, so the parser falls back to positional names.
     *
     * @return list<string>
     */
    private function resolveColumns(string $schema, string $table): array
    {
        try {
            if ($this->schemaConnection === null) {
                $this->schemaConnection = new Connection($this->host, $this->port, $this->username, $this->password, $this->ssl, $this->sslVerify, $this->sslCa);
                $this->schemaConnection->connect();
                $this->schemaConnection->execute('SET SESSION group_concat_max_len = 1048576');
            }

            // Hex literals avoid quoting/escaping entirely (and any sql_mode
            // backslash ambiguity); schema/table from the binlog are non-empty.
            $schemaHex = '0x' . bin2hex($schema);
            $tableHex = '0x' . bin2hex($table);
            $names = $this->schemaConnection->queryScalar(
                'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR 0x00)'
                . " FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = {$schemaHex} AND TABLE_NAME = {$tableHex}",
            );

            return ($names === null || $names === '') ? [] : explode("\0", $names);
        } catch (\Throwable) {
            $this->schemaConnection = null; // drop a broken connection; retried next call

            return [];
        }
    }

    /**
     * Blocking generator yielding a {@see Change} per ROWS event. Yields the
     * current coroutine while waiting on the socket.
     *
     * @return \Generator<Change>
     */
    public function getChanges(): \Generator
    {
        while (true) {
            $packet = $this->connection->readPacket();
            $marker = \ord($packet[0]);

            if ($marker === Constants::PACKET_EOF && \strlen($packet) < 9) {
                return; // end of a non-blocking stream
            }
            $this->connection->throwIfError($packet);

            $change = $this->handleEvent(substr($packet, 1));
            if ($change !== null) {
                yield $change;
            }
        }
    }

    private function handleEvent(string $event): ?Change
    {
        $eventType = \ord($event[4]); // event header: [0-3] timestamp, [4] type
        $body = substr($event, Constants::EVENT_HEADER_SIZE);
        if ($this->checksum) {
            $body = substr($body, 0, -4);
        }

        switch (true) {
            case $eventType === Constants::GTID_EVENT:
                $this->trackGtid($body);
                return null;
            case $eventType === Constants::XID_EVENT:
                $this->commit();
                return null;
            case $eventType === Constants::TABLE_MAP_EVENT:
                $this->parser->parseTableMap($body);
                return null;
            case isset(self::ROWS_EVENTS[$eventType]):
                return $this->buildChange($eventType, $body);
            default:
                return null;
        }
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

    private function registerSlave(): void
    {
        $payload = \chr(Constants::COM_REGISTER_SLAVE)
            . pack('V', $this->serverId)
            . \chr(0) // hostname
            . \chr(0) // user
            . \chr(0) // password
            . pack('v', $this->port)
            . pack('V', 0) // replication rank
            . pack('V', 0); // master id

        $this->connection->writeCommand($payload);
        $this->connection->readOk();
    }

    private function sendDumpCommand(): void
    {
        $encoded = $this->executed->encode();

        $payload = \chr(Constants::COM_BINLOG_DUMP_GTID)
            . pack('v', 0) // flags
            . pack('V', $this->serverId)
            . pack('V', 0) // binlog filename length
            . pack('P', 4) // binlog position
            . pack('V', \strlen($encoded))
            . $encoded;

        $this->connection->writeCommand($payload);
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
