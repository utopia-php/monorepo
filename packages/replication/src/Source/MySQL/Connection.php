<?php

declare(strict_types=1);

namespace Utopia\Replication\Source\MySQL;

/**
 * Streams raw binlog events from a live MySQL server over the replication
 * protocol: authenticate, register as a slave, request a GTID dump, then hand
 * each protocol packet's event payload to the caller.
 *
 * Requirements on the source server:
 *  - `binlog_format = ROW`
 *  - `gtid_mode = ON`
 *  - a user with REPLICATION SLAVE (and REPLICATION CLIENT) privileges
 */
final class Connection implements Transport
{
    private Client $client;
    private bool $checksum = false;
    private string $position = '';

    /**
     * @param int   $serverId  Unique id among the source's replicas.
     * @param float $heartbeat How often (seconds) to ask the source for a
     *                         heartbeat so an idle stream keeps the socket
     *                         active instead of tripping the read timeout. Keep
     *                         it below the connection timeout; 0 disables them.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly int $serverId,
        private readonly bool $ssl = false,
        private readonly bool $sslVerify = true,
        private readonly string $sslCa = '',
        private readonly float $heartbeat = 15.0,
    ) {}

    /**
     * @param string|null $position Executed-GTID-set to resume from. When null,
     *                              starts from the server's current position
     *                              (only new changes).
     */
    public function open(?string $position = null): void
    {
        $this->client = new Client($this->host, $this->port, $this->username, $this->password, $this->ssl, $this->sslVerify, $this->sslCa);
        $this->client->connect();

        $this->client->execute('SET @master_binlog_checksum = @@global.binlog_checksum');
        $checksum = $this->client->queryScalar('SELECT @@global.binlog_checksum') ?? 'NONE';
        $this->checksum = strtoupper(trim($checksum)) !== 'NONE';

        if ($this->heartbeat > 0) {
            $this->client->execute('SET @master_heartbeat_period = ' . (int) ($this->heartbeat * 1_000_000_000));
        }

        $this->registerSlave();

        $this->position = ($position !== null && $position !== '')
            ? $position
            : ($this->client->queryScalar('SELECT @@global.gtid_executed') ?? '');

        $this->sendDumpCommand(new GtidSet($this->position));
    }

    /**
     * @return \Generator<string>
     */
    public function events(): \Generator
    {
        while (true) {
            $packet = $this->client->readPacket();
            $marker = \ord($packet[0]);

            if ($marker === Constants::PACKET_EOF && \strlen($packet) < 9) {
                return; // end of a non-blocking stream
            }
            $this->client->throwIfError($packet);

            yield substr($packet, 1); // strip the OK marker; leave the event record
        }
    }

    public function checksum(): bool
    {
        return $this->checksum;
    }

    public function position(): string
    {
        return $this->position;
    }

    public function close(): void
    {
        if (isset($this->client)) {
            $this->client->close();
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

        $this->client->writeCommand($payload);
        $this->client->readOk();
    }

    private function sendDumpCommand(GtidSet $executed): void
    {
        $encoded = $executed->encode();

        $payload = \chr(Constants::COM_BINLOG_DUMP_GTID)
            . pack('v', 0) // flags
            . pack('V', $this->serverId)
            . pack('V', 0) // binlog filename length
            . pack('P', 4) // binlog position
            . pack('V', \strlen($encoded))
            . $encoded;

        $this->client->writeCommand($payload);
    }
}
