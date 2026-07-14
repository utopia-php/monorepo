# Contributing

We ❤️ pull requests. All Utopia libraries are developed in this monorepo — the `github.com/utopia-php/<name>` repositories are read-only mirrors, so issues and pull requests belong here.

If you don't know where to start, open an issue and a maintainer can guide you, or ask the [Appwrite team on Discord](https://appwrite.io/discord).

## Code of conduct

Help us keep Utopia open and inclusive. Please read and follow our [code of conduct](CODE_OF_CONDUCT.md).

## Getting started

You need PHP 8.4 or later and [Composer](https://getcomposer.org). Fork this repository, clone your fork, and create a branch from `main`.

Each library lives in `packages/<name>` and is an independent Composer package. Edit code there — cross-package changes are fine in a single commit.

## Before you open a pull request

Run the monorepo tooling for every package you touched:

```bash
bin/monorepo check <name> --fix   # code style (Pint), PHPStan, Rector
bin/monorepo test <name>          # the package's test suite
bin/monorepo validate             # package conventions (CI enforces this)
```

Add `--linked` to `bin/monorepo test` to run against the monorepo siblings instead of released versions — CI does this for dependents of changed packages.

Markdown documentation is linted with [Vale](https://vale.sh) (`vale README.md docs packages`); errors fail CI.

## Pull requests

- Keep each pull request focused on a single concern, with a commit message that describes the change.
- For bug fixes, documentation updates, and small improvements, open a pull request directly.
- For larger API changes or new features, open an issue first so maintainers can confirm the direction before you invest in an implementation.

Releases are made by maintainers, by tagging the monorepo `<package>/<version>` — never edit a changelog to release.

## Security

For security issues, do not open a public issue. Email [security@appwrite.io](mailto:security@appwrite.io) instead.

## Other ways to help

Pull requests are great, but there are many other ways to contribute: report bugs, improve documentation, write blog posts or tutorials, speak at meetups, or help someone on [Discord](https://appwrite.io/discord).
