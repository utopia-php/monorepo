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
binlog_row_metadata = FULL   # optional: ships column names in the stream
```

`binlog_row_metadata = FULL` lets column names ride along in the binlog. When it
is `MINIMAL` (the MySQL default, and what some managed providers are fixed to)
the reader resolves names from `INFORMATION_SCHEMA` over a second connection
instead — no configuration change required.

The connecting user needs the `REPLICATION SLAVE` and `REPLICATION CLIENT` privileges.

## Usage

```php
use Utopia\Replication\Source\MySQL;

// Source is the polymorphic interface; MySQL is the binlog implementation.
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

### Reading from a binlog file

Decoding and transport are separate: a `Decoder` turns raw binlog events into
`Change`s, and a `Transport` supplies those events. The `MySQL` source above is a
live `MySQL\Connection` transport wired to a `Decoder`; swap in a `MySQL\File`
transport to decode an archived binlog file — the same bytes `mysqlbinlog --raw`
writes, or a segment pulled from object storage — with no server and no
replication privileges:

```php
use Utopia\Replication\Source\MySQL\Decoder;
use Utopia\Replication\Source\MySQL\EventParser;
use Utopia\Replication\Source\MySQL\File;
use Utopia\Replication\Source\MySQL\GtidSet;

$source = new File($bytes);   // a string, or an iterable of byte chunks
$source->open();

// Offline there is no server to resolve column names from, so the binlog must
// carry them (binlog_row_metadata=FULL) or you pass EventParser a resolver.
$decoder = new Decoder(new EventParser(), new GtidSet(), 'appwrite', $source->checksum());

foreach ($source->events() as $event) {
    $change = $decoder->decode($event);
    if ($change !== null) {
        // react to the change ...
    }
}
```

## Scope

Deliberately minimal: MySQL 8, ROW-format binlog, and the event types needed for
CDC (TABLE_MAP, WRITE/UPDATE/DELETE_ROWS, GTID, ROTATE, XID). JSON column values
are skipped (bytes advanced, not decoded). Column names come from FULL row
metadata when available, otherwise from a single cached `INFORMATION_SCHEMA`
lookup per table.

## Tests

```bash
composer test        # unit (pure decoders — no server needed)
docker compose up -d --wait
composer test:e2e    # against a real MySQL 8 (basic CRUD, >16MiB rows,
                     # caching_sha2 full-auth, TLS)
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
