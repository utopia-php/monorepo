# Utopia Monorepo

The source of truth for the [utopia-php](https://github.com/utopia-php) libraries. Development happens here; each library is distributed to its own read-only repository (e.g. `utopia-php/http`) by subtree split, so Composer/Packagist distribution is unchanged.

## Layout

```
packages/<name>/   one Composer library, mirrored to github.com/utopia-php/<name>
bin/monorepo       all monorepo tooling (dependency-free PHP, built on git subtree)
pint.json          canonical code style for every package
composer.json      pins the shared toolchain (pint, phpstan, rector)
```

Code style is monorepo-wide; phpstan levels and rector rules stay per-package
(`phpstan.neon`, `rector.php`) since they encode per-library decisions.

## Commands

```sh
bin/monorepo list                  # packages and their latest release tag
bin/monorepo import <name>         # bring an existing library in, preserving full history
bin/monorepo validate              # check package conventions
bin/monorepo check [name...]       # run pint, phpstan and rector (--fix to apply)
bin/monorepo test [name...]        # run package test suites
bin/monorepo split [name...]       # push subtrees to the distribution repositories (CI does this)
```

## How distribution works

On every push to `main`, CI splits each `packages/<name>` directory into a standalone history with `git subtree split` and pushes it to `utopia-php/<name>`. Because libraries are imported with `git subtree add` (full history preserved), the split reproduces the original commit hashes and pushes fast-forward on top of each repository's existing history.

The distribution repositories become read-only mirrors: archive their open PRs, enable branch protection, and point contributors here.

## Releasing

Tag the monorepo `<package>/<version>` and push:

```sh
git tag http/2.1.0 && git push origin http/2.1.0
```

CI pushes tag `2.1.0` to `utopia-php/http`, and Packagist picks it up as usual. Nothing changes for consumers.

## Importing the remaining libraries

```sh
bin/monorepo import database
git push
```

One command per library. The first CI split after an import must reach the same history the mirror already has — if the mirror diverged (e.g. a commit landed there after the import), re-import or run `bin/monorepo split <name> --force` once.

## CI setup

The `Split` workflow authenticates as a GitHub App with `contents: write`, installed on the `utopia-php` org. In this repository's **Settings → Secrets and variables → Actions**, set:

- variable `SPLIT_APP_ID` — the App ID (or client ID)
- secret `SPLIT_APP_PRIVATE_KEY` — the app's private key (`.pem` contents, generated in the app's settings)

## Cross-package development

Packages declare their dependencies normally (resolved from Packagist), so each mirror keeps working standalone. To develop a package against a local sibling:

```sh
cd packages/http
composer config repositories.local path ../di
composer require utopia-php/di:@dev
```

Revert `composer.json` before committing — `bin/monorepo validate` and CI test against released versions.
