# Distribution

How code in `packages/<name>` reaches `github.com/utopia-php/<name>` and Packagist.

## Subtree splits

On every push to `main`, CI splits each `packages/<name>` directory into a standalone history and pushes it to `utopia-php/<name>`. Libraries are imported with `git subtree add` (full history preserved); the split itself is computed by `bin/monorepo` — starting from the latest `git-subtree-*` annotation, it re-synthesizes one commit per mainline commit that changed the package, byte-identical to `git subtree split` output but immune to its fatal cache collision when a package is removed and re-imported. Splits are deterministic and push fast-forward on top of each mirror's existing history.

The distribution repositories become read-only mirrors: archive their open PRs, enable branch protection, and point contributors to the monorepo.

## Dev branches

Before the monorepo split, an in-flight PR branch on a library's own repository was directly installable (`composer require utopia-php/http:dev-my-branch`) — handy for unblocking a consumer without waiting for a release. The `Split Dev` workflow restores that: dispatch it (**Actions → Split Dev → Run workflow**) on the feature branch, naming the package(s) to publish, and it pushes the branch's split to the mirror under the same branch name. Packagist tracks the mirror's branches, so the consumer can then require:

```json
{ "require": { "utopia-php/http": "dev-my-branch as 2.1.999" } }
```

The inline alias (aliasing into the latest released minor) keeps other packages' constraints on the library resolving; the workflow's run summary prints the exact line to copy. Notes:

- The mirror branch is force-pushed on every dispatch (rebases change the synthesized history), so re-dispatch after pushing new commits and run `composer update` in the consumer.
- Mirror rulesets only protect the default branch, so dev branches need no bypass — but they also never publish to `main` or tags; the workflow refuses to run on `main`.
- When the branch merges (or is abandoned), re-dispatch with **action: delete** to remove the mirror branch. Stale branches are harmless but noisy.

## Release pipeline

A monorepo tag shaped `<package>/<semver>` (e.g. `http/2.1.0`) triggers CI to push tag `2.1.0` to `utopia-php/http` (Packagist picks it up as usual) and publish a GitHub release on the mirror whose notes are every monorepo commit that touched `packages/http` since the previous release, with a compare link back to the monorepo.

## CI setup

The `Split` workflow authenticates as a GitHub App with `contents: write`, installed on the `utopia-php` org. In the monorepo's **Settings → Secrets and variables → Actions**, set:

- variable `SPLIT_APP_ID` — the App ID (or client ID)
- secret `SPLIT_APP_PRIVATE_KEY` — the app's private key (`.pem` contents, generated in the app's settings)
