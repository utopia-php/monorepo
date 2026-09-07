# Utopia Reputation

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/reputation`](https://github.com/utopia-php/monorepo/tree/main/packages/reputation) — please open issues and pull requests there.

[![Build Status](https://github.com/utopia-php/reputation/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/reputation/actions/workflows/tests.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/reputation.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Reputation looks up an IP address in a [Verdict](https://github.com/appwrite-labs/verdict) MaxMind database (MMDB) and returns a typed record: verdict, score, and categories. Constructing the client, and calling `get()`, does not open the file. The lookup runs on the first field read and is reused for later accessors on the same record. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it can be used as a standalone package in any PHP project.

## Getting started

Install using Composer:

```bash
composer require utopia-php/reputation
```

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Utopia\Reputation\Reputation;
use Utopia\Reputation\Verdict;

$reputation = new Reputation('/path/to/verdict.mmdb');
$record = $reputation->get($_SERVER['REMOTE_ADDR'] ?? '');

echo $record->getIp();
echo $record->getVerdict();
echo $record->getScore();
print_r($record->getCategories());

if ($record->getVerdict() === Verdict::BLOCK) {
    // deny
}
```

## Record

`get($ip)` returns a `Record`. Reading `getIp()` does not search the database. `getVerdict()`, `getScore()`, and `getCategories()` run one lookup and cache it on that record.

| Method | Returns | Searches the database |
| --- | --- | --- |
| `getIp()` | `string` | No |
| `getVerdict()` | `string` (`Verdict::*`) | Yes, on first call |
| `getScore()` | `int` | Yes, on first call |
| `getCategories()` | `list<string>` | Yes, on first call |

The file is memory-mapped on that first field read and reopened when the file's modification time changes, so a replaced database is picked up without a process restart.

## Verdicts

`getVerdict()` returns one of:

| Constant | Value | Meaning |
| --- | --- | --- |
| `Verdict::CLEAN` | `clean` | No signals, or the lookup failed open |
| `Verdict::LOW` | `low` | Weak or uncorroborated signals |
| `Verdict::SUSPICIOUS` | `suspicious` | Multiple or stronger signals |
| `Verdict::BLOCK` | `block` | High-confidence block list match |

Unknown verdict strings in the database are treated as `Verdict::CLEAN`.

## Fail open

A missing file, an unreadable database, an invalid IP, a failed MaxMind read, or an unknown verdict returns `Verdict::CLEAN` with score `0` and no categories. Callers can deny only on an explicit `Verdict::BLOCK` (or another verdict they choose) without treating lookup failures as blocks.

Invalid IP addresses never call into the database.

## Database

Point the constructor at a Verdict MMDB:

```php
$reputation = new Reputation('/var/lib/verdict/verdict.mmdb');
```

Download `verdict.mmdb` from the [Verdict releases](https://github.com/appwrite-labs/verdict/releases). The library does not ship the database.

## System requirements

Utopia Reputation requires PHP 8.4 or later. We recommend using the latest PHP version whenever possible.

The MaxMind reader (`maxmind-db/reader`) is a Composer dependency. The optional `ext-maxminddb` extension speeds up lookups when it is installed.

## Tests

From the monorepo root:

```bash
bin/monorepo check reputation
bin/monorepo test reputation
```

From this package:

```bash
composer test
```

Unit tests run on a bare host with no live database. They cover lazy field reads, invalid IP addresses, unknown verdicts, and a missing MMDB path. They do not download Verdict data.

## Security

We take security seriously. If you discover any security-related issues, please email security@appwrite.io instead of using the issue tracker.

## Contributing

All code contributions — including those of people having commit access — must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
