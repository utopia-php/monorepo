# Utopia Reputation

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/reputation`](https://github.com/utopia-php/monorepo/tree/main/packages/reputation) — please open issues and pull requests there.

In-process IP reputation lookups against a [Verdict](https://github.com/appwrite-labs/verdict) MMDB.

Constructing `Reputation` does not open the database. `get()` memory-maps the
file on first use and reopens it when the file's mtime changes, so a replaced
MMDB is picked up without a process restart. Callers that never look up an IP
never map the file.

A missing file, an unreadable database, an invalid IP, or an unknown verdict
returns `Verdict::Clean`.

## Installation

```bash
composer require utopia-php/reputation
```

## Quick start

```php
use Utopia\Reputation\Reputation;
use Utopia\Reputation\Verdict;

$reputation = new Reputation('/path/to/verdict.mmdb');
$record = $reputation->get($_SERVER['REMOTE_ADDR'] ?? '');

echo $record->verdict->value; // clean, low, suspicious, or block
echo $record->score;
print_r($record->categories);

if ($record->verdict === Verdict::Block) {
    // deny
}
```
