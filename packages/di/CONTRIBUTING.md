# Contributing

Thanks for contributing to Utopia DI.

This repository contains a small PSR-11 compatible dependency injection container used across the Utopia libraries. Contributions should keep that scope intact: small surface area, predictable behavior, and strong test coverage.

## Code Of Conduct

Please read and follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Before You Start

- For bug fixes, documentation updates, and small improvements, open a pull request directly.
- For larger API changes or new features, open an issue first so maintainers can confirm the direction before implementation.
- For security issues, do not open a public issue. Email `security@appwrite.io` instead.

## Development Setup

Utopia DI requires PHP 8.2 or later.

1. Fork the repository and clone your fork.
2. Create a branch from `main`.
3. Install root dependencies:

```bash
composer install
```

4. Install Rector dependencies if you plan to run refactoring checks:

```bash
composer install -d tools/rector
```

## Branches And Commits

Use a short, descriptive branch name. Examples:

- `fix/scope-cache-behavior`
- `docs/readme-scope-example`
- `chore/update-tooling`

Write commit messages that clearly describe the change. Keep each pull request focused on a single concern.

## Local Checks

Run the relevant checks before opening a pull request:

```bash
composer test
composer analyze
composer format:check
composer refactor:check
```

If you want to apply the automated fixes first:

```bash
composer fix
```

Notes:

- `composer test` runs PHPUnit using [phpunit.xml](phpunit.xml).
- `composer analyze` runs PHPStan using [phpstan.neon](phpstan.neon).
- `composer format:check` runs Pint in check mode.
- `composer refactor:check` requires `tools/rector` dependencies to be installed first.

## Pull Requests

When opening a pull request:

- Base it on `main`.
- Explain the problem and the approach you took to solve it.
- Link the related issue when there is one.
- Add or update tests for behavior changes.
- Update documentation when public behavior or examples change.
- Avoid mixing unrelated refactors with functional changes.

All changes should go through pull request review before merging.

## What Maintainers Look For

The strongest contributions usually have these properties:

- The change matches the library's narrow scope.
- The public API stays simple and consistent.
- Edge cases are covered with tests.
- Error messages stay clear and actionable.
- Documentation reflects the final behavior.

## Other Ways To Help

You can also contribute by:

- reporting bugs and inconsistencies
- improving examples and documentation
- reviewing pull requests
- helping other users in issues and community channels
