<?php

namespace Utopia\Replication\Checkpoint;

use Utopia\Replication\Checkpoint;
use Utopia\Replication\Source\MySQL\Client;

/**
 * Stores the position in a MySQL table via INSERT … ON DUPLICATE KEY UPDATE,
 * keyed by {@see $key} so several consumers (e.g. one per region) can share a
 * table. The table is created on first use:
 *
 *   CREATE TABLE IF NOT EXISTS `<database>`.`<table>`
 *       (`id` VARCHAR(191) PRIMARY KEY, `position` TEXT NOT NULL)
 *
 * IMPORTANT: point this at a WRITABLE database the Source does NOT tail. A
 * checkpoint written into the replicated database would be streamed back as a
 * change and self-loop, and a read replica cannot be written at all. The
 * `<database>` must already exist. Writes are coalesced to at most one per
 * {@see $interval} seconds; resuming is idempotent, so the replay window after a
 * crash is harmless.
 */
final class MySQL implements Checkpoint
{
    private ?Client $client = null;
    private ?string $pending = null;
    private float $writtenAt = 0.0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $key,
        private readonly string $database,
        private readonly string $table = 'replication_checkpoint',
        private readonly bool $ssl = false,
        private readonly bool $sslVerify = true,
        private readonly string $sslCa = '',
        private readonly float $interval = 1.0,
    ) {}

    public function get(): ?string
    {
        $value = $this->run(fn(Client $client): ?string => $client->queryScalar(
            'SELECT `position` FROM ' . $this->qualified() . ' WHERE `id` = ' . $this->literal($this->key),
        ));

        return ($value === null || $value === '') ? null : $value;
    }

    public function set(string $position): void
    {
        $this->pending = $position;

        if (microtime(true) - $this->writtenAt >= $this->interval) {
            $this->flush();
        }
    }

    /**
     * Force a coalesced position to disk. Call before shutting down so the latest
     * position is not left waiting in the write window.
     */
    public function flush(): void
    {
        if ($this->pending === null) {
            return;
        }

        $position = $this->pending;
        $this->run(function (Client $client) use ($position): null {
            $client->execute(
                'INSERT INTO ' . $this->qualified() . ' (`id`, `position`) VALUES (' . $this->literal($this->key) . ', ' . $this->literal($position) . ')'
                . ' ON DUPLICATE KEY UPDATE `position` = VALUES(`position`)',
            );

            return null;
        });

        $this->writtenAt = microtime(true);
        $this->pending = null;
    }

    /**
     * Run an operation against a live client, (re)connecting as needed. A dropped
     * connection is discarded so the next call reconnects rather than wedging.
     *
     * @template T
     * @param  \Closure(Client): T  $operation
     * @return T
     */
    private function run(\Closure $operation): mixed
    {
        try {
            return $operation($this->client());
        } catch (\Throwable $e) {
            $this->client = null;
            throw $e;
        }
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $client = new Client($this->host, $this->port, $this->username, $this->password, $this->ssl, $this->sslVerify, $this->sslCa);
            $client->connect();
            $client->execute(
                'CREATE TABLE IF NOT EXISTS ' . $this->qualified() . ' (`id` VARCHAR(191) NOT NULL PRIMARY KEY, `position` TEXT NOT NULL)',
            );
            $this->client = $client;
        }

        return $this->client;
    }

    private function qualified(): string
    {
        return $this->identifier($this->database) . '.' . $this->identifier($this->table);
    }

    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function literal(string $value): string
    {
        // Hex literals sidestep quoting/escaping (and any sql_mode ambiguity).
        return $value === '' ? "''" : '0x' . bin2hex($value);
    }
}
