# Utopia Monorepo

- Each `packages/<name>` is an independent Composer library, mirrored read-only to `github.com/utopia-php/<name>`. Keep changes scoped per package; cross-package changes are fine in one commit.
- Package `composer.json` must keep `name: utopia-php/<name>`, declare no `version`, and depend on siblings via Packagist constraints (never path repositories). Run `bin/monorepo validate` after touching one.
- Releases are git tags shaped `<package>/<semver>` (e.g. `http/2.1.0`) — never edit a changelog to "release".
- All monorepo tooling lives in `bin/monorepo`; do not add monorepo packages or frameworks for this.
- Run a package's tests with `bin/monorepo test <name>`; add `--linked` to test against monorepo siblings instead of released versions (CI does this for dependents of changed packages). Test contract: `composer test` = unit tier, bare host; `composer test:e2e` = host-run tests against the package's compose services (offset host ports, never tests inside containers).
- Markdown docs are linted with Vale (`vale README.md docs packages`; config in `.vale.ini`, vendored style in `.vale/styles`). Errors fail CI. Opinions: sentence-case headings (H1 is the library's proper name and is exempt), no weasel words ("simply", "easily"), correct brand casing (GitHub, Composer, PHPStan), spell-check with the shared vocabulary in `.vale/styles/config/vocabularies/Utopia/accept.txt` — add legitimate new terms there, backtick code identifiers in prose.
- Benchmarks are opt-in and run separately from tests: a package joins by declaring a `composer bench` script, and `bin/monorepo benchmark <name>` runs it. The Benchmark workflow runs these on Linux runners (informational, not a pass/fail gate); packages without a `bench` script are skipped.
