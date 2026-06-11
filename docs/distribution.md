# Distribution

How code in `packages/<name>` reaches `github.com/utopia-php/<name>` and Packagist.

## Subtree splits

On every push to `main`, CI splits each `packages/<name>` directory into a standalone history and pushes it to `utopia-php/<name>`. Libraries are imported with `git subtree add` (full history preserved); the split itself is computed by `bin/monorepo` — starting from the latest `git-subtree-*` annotation, it re-synthesizes one commit per mainline commit that changed the package, byte-identical to `git subtree split` output but immune to its fatal cache collision when a package is removed and re-imported. Splits are deterministic and push fast-forward on top of each mirror's existing history.

The distribution repositories become read-only mirrors: archive their open PRs, enable branch protection, and point contributors to the monorepo.

## Release pipeline

A monorepo tag shaped `<package>/<semver>` (e.g. `http/2.1.0`) triggers CI to push tag `2.1.0` to `utopia-php/http` (Packagist picks it up as usual) and publish a GitHub release on the mirror whose notes are every monorepo commit that touched `packages/http` since the previous release, with a compare link back to the monorepo.

## CI setup

The `Split` workflow authenticates as a GitHub App with `contents: write`, installed on the `utopia-php` org. In the monorepo's **Settings → Secrets and variables → Actions**, set:

- variable `SPLIT_APP_ID` — the App ID (or client ID)
- secret `SPLIT_APP_PRIVATE_KEY` — the app's private key (`.pem` contents, generated in the app's settings)
