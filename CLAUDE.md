# Utopia Monorepo

- Each `packages/<name>` is an independent Composer library, mirrored read-only to `github.com/utopia-php/<name>`. Keep changes scoped per package; cross-package changes are fine in one commit.
- Package `composer.json` must keep `name: utopia-php/<name>`, declare no `version`, and depend on siblings via Packagist constraints (never path repositories). Run `bin/monorepo validate` after touching one.
- Releases are git tags shaped `<package>/<semver>` (e.g. `http/2.1.0`) — never edit a changelog to "release".
- All monorepo tooling lives in `bin/monorepo`; do not add monorepo packages or frameworks for this.
- Run a package's tests with `bin/monorepo test <name>`. Test contract: `composer test` = unit tier, bare host; `composer test:e2e` = host-run tests against the package's compose services (offset host ports, never tests inside containers).
