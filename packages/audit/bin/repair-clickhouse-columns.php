<?php

/**
 * Add the schema columns a deployed ClickHouse audit table is missing.
 *
 * setup() is CREATE TABLE IF NOT EXISTS, so it cannot reconcile a table that
 * already exists. Where the tables are provisioned out of band — Appwrite Cloud
 * skips all schema setup when _APP_ENV=production — a column added to the
 * schema in a later release never reaches them, and the table keeps whatever
 * shape it was created with. Nothing else repairs this, so it is done here.
 *
 * Observed in cloud-nyc3-prod on 2026-08-30, 419 times in two hours:
 *
 *   Failed to log slow query: ClickHouse query execution failed: ClickHouse
 *   query failed with HTTP 500: Code: 16. DB::Exception: No such column
 *   resource in table appwriteCloud.projects_slow_queries
 *   (98c973c6-e86f-4555-bfef-09d96ffa1e4d). (NO_SUCH_COLUMN_IN_TABLE)
 *   (version 26.4.3.37 (official build))
 *
 * Usage:
 *   php bin/repair-clickhouse-columns.php --dsn='https://user:pass@host:8443/appwriteCloud' \
 *       --table=slow_queries --namespaces=projects,console [--execute]
 *
 * Without --execute it prints the DDL it would run and changes nothing.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Audit\Adapter\ClickHouse;

$options = getopt('', ['dsn:', 'table::', 'namespaces::', 'execute']);

$dsnRaw = $options['dsn'] ?? getenv('_APP_CONNECTIONS_DB_AUDITS');
if (!is_string($dsnRaw) || trim($dsnRaw) === '') {
    fwrite(STDERR, "Missing --dsn (or _APP_CONNECTIONS_DB_AUDITS).\n");
    exit(1);
}

$parts = parse_url(trim($dsnRaw));
if (!is_array($parts) || !isset($parts['host'])) {
    fwrite(STDERR, "Could not parse the DSN.\n");
    exit(1);
}

$database = trim((string) ($parts['path'] ?? ''), '/');
if ($database === '') {
    fwrite(STDERR, "The DSN has no database in its path.\n");
    exit(1);
}

// Only a plain http:// DSN is treated as insecure; anything else (https://,
// clickhouse://) stays on TLS so a typo cannot silently downgrade a production
// connection.
$secure = ($parts['scheme'] ?? '') !== 'http';

$table = is_string($options['table'] ?? null) && $options['table'] !== '' ? $options['table'] : 'audits';

$namespacesRaw = is_string($options['namespaces'] ?? null) && $options['namespaces'] !== ''
    ? $options['namespaces']
    : 'projects,console';
$namespaces = array_values(array_filter(array_map(trim(...), explode(',', $namespacesRaw)), fn(string $n): bool => $n !== ''));

$execute = array_key_exists('execute', $options);

$clickhouse = new ClickHouse(
    host: $parts['host'],
    port: (int) ($parts['port'] ?? 8123),
    username: urldecode((string) ($parts['user'] ?? 'default')),
    password: urldecode((string) ($parts['pass'] ?? '')),
    secure: $secure,
);
$clickhouse->setDatabase($database);

$failed = false;

foreach ($namespaces as $namespace) {
    $adapter = (clone $clickhouse)->setNamespace($namespace)->setTable($table);
    $qualified = $database . '.' . $adapter->getTableName();

    try {
        $missing = $adapter->getMissingColumns();
    } catch (Throwable $e) {
        fwrite(STDERR, "{$qualified}: {$e->getMessage()}\n");
        $failed = true;
        continue;
    }

    if ($missing === []) {
        echo "{$qualified}: up to date, nothing to add.\n";
        continue;
    }

    $ddl = 'ALTER TABLE `' . $database . '`.`' . $adapter->getTableName() . '` '
        . implode(', ', array_map(fn(string $d): string => 'ADD COLUMN IF NOT EXISTS ' . $d, $missing));

    echo "{$qualified}: missing " . count($missing) . ' column(s): ' . implode(', ', array_keys($missing)) . "\n";
    echo "  {$ddl}\n";

    if (!$execute) {
        continue;
    }

    try {
        $adapter->ensureColumns();
        $remaining = $adapter->getMissingColumns();
    } catch (Throwable $e) {
        fwrite(STDERR, "{$qualified}: {$e->getMessage()}\n");
        $failed = true;
        continue;
    }

    if ($remaining !== []) {
        fwrite(STDERR, "{$qualified}: still missing " . implode(', ', array_keys($remaining)) . " after the ALTER.\n");
        $failed = true;
        continue;
    }

    echo "{$qualified}: added " . implode(', ', array_keys($missing)) . ".\n";
}

if (!$execute) {
    echo "\nDry run; re-run with --execute to apply.\n";
}

exit($failed ? 1 : 0);
