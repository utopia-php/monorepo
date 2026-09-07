# Utopia Reputation

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/reputation`](https://github.com/utopia-php/monorepo/tree/main/packages/reputation) — please open issues and pull requests there.

In-process IP reputation lookups against a [Verdict](https://github.com/appwrite-labs/verdict) MMDB.

Constructing `Reputation` and calling `get()` do not open the database. The
file is memory-mapped on the first `getVerdict()`, `getScore()`, or
`getCategories()` call and reopened when the file's modification time
changes, so a replaced MMDB is picked up without a process restart.

A missing file, an unreadable database, an invalid IP, or an unknown verdict
returns `Verdict::CLEAN`.

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

echo $record->getVerdict(); // clean, low, suspicious, or block
echo $record->getScore();
print_r($record->getCategories());

if ($record->getVerdict() === Verdict::BLOCK) {
    // deny
}
```
