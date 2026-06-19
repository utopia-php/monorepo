# Utopia Replication

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/replication`](https://github.com/utopia-php/monorepo/tree/main/packages/replication) — please open issues and pull requests there.

[![Build Status](https://github.com/utopia-php/replication/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/replication/actions/workflows/tests.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/replication.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Replication is a Swoole-native MySQL binlog reader. It streams row-level changes (change data capture) from a MySQL server over the replication protocol, so a consumer can react to writes — invalidate caches, build projections, fan out events — by tailing a server locally instead of relying on application-level messaging. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/replication
```

## System Requirements

Utopia Replication requires PHP 8.3 or later, and the [Swoole](https://github.com/swoole/swoole-src) extension (>=6.0) for coroutine socket I/O. The [OpenSSL](https://www.php.net/manual/en/book.openssl.php) extension is required for `caching_sha2_password` full authentication and TLS.

### Source server

The MySQL source must be configured for GTID-based row replication:

```ini
binlog_format       = ROW
gtid_mode           = ON
enforce_gtid_consistency = ON
binlog_row_metadata = FULL   # so column names arrive in the stream
```

The connecting user needs the `REPLICATION SLAVE` and `REPLICATION CLIENT` privileges.

## Usage

```php
use Utopia\Replication\Adapter;
use Utopia\Replication\Adapter\MySQL;

// Adapter is the polymorphic interface; MySQL is the binlog implementation.
$replication = new MySQL(
    host: '127.0.0.1',
    port: 3306,
    username: 'replicator',
    password: 'secret',
    serverId: 1001,            // unique among replicas
    schema: 'appwrite',        // only emit changes for this database
    ssl: false,                // when true, TLS verifies the server cert by default
);

$replication->start($checkpoint ?? null);  // resume from a GTID set, or null for "now"

foreach ($replication->getChanges() as $change) {
    // $change->action  — Change::INSERT | UPDATE | DELETE
    // $change->table   — physical table name
    // $change->rows    — list of column => value maps (after-image for updates)
    // $change->gtid    — executed-GTID-set checkpoint (advance on commit)

    foreach ($change->rows as $row) {
        // react to the change ...
    }

    $checkpoint = $change->gtid;     // persist to resume after a restart
}
```

The reader runs inside a Swoole coroutine; `getChanges()` blocks (yielding the
coroutine) while waiting for events. The GTID checkpoint advances on transaction
commit, so a crash mid-transaction re-streams it — treat changes as idempotent.

## Scope

Deliberately minimal: MySQL 8, ROW-format binlog, and the event types needed for
CDC (TABLE_MAP, WRITE/UPDATE/DELETE_ROWS, GTID, ROTATE, XID). JSON column values
are skipped (bytes advanced, not decoded). Column names come from FULL row
metadata, so no `INFORMATION_SCHEMA` lookups are required.

## Tests

```bash
composer test        # unit (pure decoders — no server needed)
docker compose up -d --wait
composer test:e2e    # against a real MySQL 8 (basic CRUD, >16MiB rows,
                     # caching_sha2 full-auth, TLS)
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
