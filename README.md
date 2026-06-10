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
bin/monorepo absorb <name>         # run the absorption playbook (import + strip QA + mirror lockdown)
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

## Absorbing a library

One command runs most of the playbook:

```sh
bin/monorepo absorb database
```

It imports the library with full history, strips the QA tooling the monorepo hoists (pint/phpstan/rector dependencies, scripts and `pint.json`, refreshing `composer.lock` if one is committed), writes a `mirror.yml` workflow that closes pull requests opened against the mirror with a redirect here, banners the README, and creates the mirror ruleset (PR-only, no force-push, split app bypassed). Every step is idempotent — re-run it freely.

Then, by hand:

1. `bin/monorepo check <name> --fix` — apply the canonical style; fix whatever phpstan and rector surface.
2. `bin/monorepo test <name>` — suites with a compose `tests` service run in containers.
3. Review `packages/<name>/.github/workflows/` — delete QA-only workflows (pint, phpstan, linters); keep test workflows, they validate the mirror after each split.
4. Commit, push, and confirm the Split run is green. If the mirror push is rejected:
   - `master` default branch — rename first: `gh api -X POST repos/utopia-php/<name>/branches/master/rename -f new_name=main`
   - `protected branch hook declined` — classic branch protection; let the app bypass it:
     `echo '{"bypass_pull_request_allowances":{"apps":["utopia-php-monorepo"]}}' | gh api -X PATCH repos/utopia-php/<name>/branches/main/protection/required_pull_request_reviews --input -`
   - diverged mirror (a commit landed upstream after the import) — re-import, or `bin/monorepo split <name> --force` once.
5. Triage pull requests already open on the mirror (the redirect only covers new ones), then make the library's next release from here.

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
