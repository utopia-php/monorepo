# Creating a new library

There is no `monorepo new` command — a fresh package is just a handful of files
plus the same mirror plumbing every package gets. Scaffold the files, create the
(empty) mirror repo, then let `bin/monorepo absorb` wire up the mirror: with
`packages/<name>` already present it skips the import and only does the
mirror-side work (writes `mirror.yml`, banners the README, creates the lockdown
ruleset, strips any hoisted QA tooling).

Throughout, `<name>` is the package slug (e.g. `cache`) and `<Ns>` its
studly-cased namespace segment (e.g. `Cache`).

## 1. Scaffold the package

```sh
mkdir -p packages/<name>/src/<Ns> packages/<name>/tests
```

Create these files. They mirror an existing small package (`packages/span` is a
good reference) — note what the monorepo hoists and therefore **must not** appear
here: no `pint.json`, and no pint/phpstan/rector/phpunit entries in
`require-dev` (the root toolchain supplies them; see `check`/`test` in
`bin/monorepo`).

**`composer.json`** — `name` must be `utopia-php/<name>`, declare a `license`,
and **never** a `version` (versions come from tags). Depend on siblings via
Packagist constraints, never path repositories.

```json
{
    "name": "utopia-php/<name>",
    "description": "<one line>",
    "type": "library",
    "license": "MIT",
    "autoload": {
        "psr-4": { "Utopia\\<Ns>\\": "src/<Ns>/" }
    },
    "autoload-dev": {
        "psr-4": { "Utopia\\<Ns>\\Tests\\": "tests/" }
    },
    "require": {
        "php": ">=8.4"
    },
    "scripts": {
        "test": "phpunit"
    }
}
```

**`phpunit.xml`**, **`phpstan.neon`**, **`rector.php`**, **`.gitignore`** — copy
from `packages/span` verbatim (the `.gitignore` ignores `/vendor/`,
`composer.lock`, and the phpunit caches). `phpstan.neon` is optional but
expected; `check` runs it only when present.

**`README.md`** — start with an `# Utopia <Ns>` H1 and an Installation /
Quick Start section. `absorb` inserts the read-only-mirror banner under the H1 in
step 3, so leave room for it.

**`CHANGELOG.md`** — `# Changelog` plus a `## <first version>` entry. Never edit
it to "release"; releases are tags.

Then write your code under `src/<Ns>/` and tests under `tests/`.

## 2. Create the empty mirror repo

The split push (step 5) targets `github.com/utopia-php/<name>`, and `absorb`'s
ruleset call needs the repo to exist:

```sh
gh repo create utopia-php/<name> --public -d "<one line>"
```

Leave it empty — CI populates it on the first split.

## 3. Wire up the mirror

```sh
bin/monorepo absorb <name>
```

Because `packages/<name>` exists it skips the import and just: strips hoisted QA
tooling (a no-op on a clean scaffold), writes
`packages/<name>/.github/workflows/mirror.yml` (closes PRs opened on the mirror
with a redirect here), adds the mirror banner to the README, and creates the
mirror ruleset (PR-only, no force-push, split app bypassed). Idempotent — re-run
freely. If `gh` can't reach the repo the ruleset step prints a manual fallback.

Add a package CI workflow if you want per-PR test runs on the mirror — copy
`packages/span/.github/workflows/ci.yaml`. Pin every `uses:` with
[ratchet](https://github.com/sethvargo/ratchet)
(`ratchet pin packages/<name>/.github/workflows/*.y*ml`); CI rejects unpinned
actions.

## 4. Verify locally

```sh
bin/monorepo check <name> --fix   # pint + phpstan + rector
bin/monorepo test <name>          # composer test (unit tier)
bin/monorepo validate             # composer.json conventions + graph freshness
bin/monorepo graph                # regenerate the README dependency graph
```

`validate` fails if the dependency graph is stale, so run `graph` whenever the
package depends on a sibling. Honour the test contract (see the README's Testing
section): unit tier in `composer test` on a bare host; any services tier in
`composer test:e2e` against a committed `docker-compose.yml`.

## 5. Commit, push, release

Commit the package and push to `main`. Unlike an imported package, a new one has
no `git-subtree-*` annotation; `bin/monorepo split` handles that by synthesizing
the package's history from the repository root (its first commit lands
parentless), so no manual seeding is needed. The `Split` workflow pushes that
history to the empty mirror, creating its `main` — confirm the run is green.

```sh
bin/monorepo split <name> --dry-run   # optional: preview the split head locally
```

Then cut the first release:

```sh
bin/monorepo release <name> <version>   # --dry-run to preview notes first
```

This tags the monorepo `<name>/<version>` and pushes it; CI mirrors the tag,
Packagist picks it up, and a GitHub release is published on the mirror. See
[distribution.md](distribution.md) for the pipeline details.
