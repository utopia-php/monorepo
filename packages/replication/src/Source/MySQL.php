<?php

namespace Utopia\Replication\Source;

use Utopia\Replication\Change;
use Utopia\Replication\Source;
use Utopia\Replication\Source\MySQL\Client;
use Utopia\Replication\Source\MySQL\Connection;
use Utopia\Replication\Source\MySQL\Decoder;
use Utopia\Replication\Source\MySQL\EventParser;
use Utopia\Replication\Source\MySQL\GtidSet;
use Utopia\Replication\Source\MySQL\Transport;

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
 * This class wires a live {@see MySQL\Connection} transport to a {@see Decoder};
 * to decode archived binlog files instead, pair a {@see MySQL\File} transport
 * with a {@see Decoder} directly.
 *
 * Usage:
 *  $replication = new MySQL($host, $port, $user, $pass, $serverId, schema: 'appwrite');
 *  $replication->start($checkpoint);
 *  foreach ($replication->getChanges() as $change) { ...; $checkpoint = $change->gtid; }
 */
class MySQL implements Source
{
    private Transport $transport;
    private Decoder $decoder;
    private readonly EventParser $parser;
    private ?Client $schemaClient = null;

    /**
     * @param string|null $schema   Only emit changes for this database; others are
     *                              decoded for bookkeeping but not yielded.
     * @param float       $heartbeat How often (seconds) to ask the source for a
     *                              heartbeat so an idle dump stream keeps the
     *                              socket active instead of tripping the read
     *                              timeout. Keep it below the connection timeout;
     *                              0 disables heartbeats.
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
        private readonly float $heartbeat = 15.0,
    ) {
        $this->parser = new EventParser(fn(string $schema, string $table): array => $this->resolveColumns($schema, $table));
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
        $this->transport = new Connection($this->host, $this->port, $this->username, $this->password, $this->serverId, $this->ssl, $this->sslVerify, $this->sslCa, $this->heartbeat);
        $this->transport->open($position);

        $this->decoder = new Decoder($this->parser, new GtidSet($this->transport->position()), $this->schema, $this->transport->checksum());
    }

    /**
     * Blocking generator yielding a {@see Change} per ROWS event. Yields the
     * current coroutine while waiting on the socket.
     *
     * @return \Generator<Change>
     */
    public function getChanges(): \Generator
    {
        foreach ($this->transport->events() as $event) {
            $change = $this->decoder->decode($event);
            if ($change !== null) {
                yield $change;
            }
        }
    }

    public function stop(): void
    {
        if (isset($this->transport)) {
            $this->transport->close();
        }
        if ($this->schemaClient !== null) {
            $this->schemaClient->close();
            $this->schemaClient = null;
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
            if ($this->schemaClient === null) {
                $this->schemaClient = new Client($this->host, $this->port, $this->username, $this->password, $this->ssl, $this->sslVerify, $this->sslCa);
                $this->schemaClient->connect();
                $this->schemaClient->execute('SET SESSION group_concat_max_len = 1048576');
            }

            // Hex literals avoid quoting/escaping entirely (and any sql_mode
            // backslash ambiguity); schema/table from the binlog are non-empty.
            $schemaHex = '0x' . bin2hex($schema);
            $tableHex = '0x' . bin2hex($table);
            $names = $this->schemaClient->queryScalar(
                'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR 0x00)'
                . " FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = {$schemaHex} AND TABLE_NAME = {$tableHex}",
            );

            return ($names === null || $names === '') ? [] : explode("\0", $names);
        } catch (\Throwable) {
            $this->schemaClient = null; // drop a broken connection; retried next call

            return [];
        }
    }
}
