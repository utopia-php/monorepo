---
name: package-design
description: Design principles and a working checklist for creating or modernizing a utopia-php package (major version redesigns, new libraries, API reviews). Distilled from the storage 3.0 rewrite.
---

# Package design

How to shape a utopia-php package. Apply when designing a new package, preparing a major version, or reviewing an API. The goal is always the same: a small, honest, immutable surface — aim for a net-negative line count on redesigns.

## State and construction

- All configuration enters through the constructor as `readonly` promoted properties. No `setX()` methods, no static mutable state, no global registries. If a value must vary per operation, make it a method argument (`transfer(..., int $chunkSize)`), not instance state.
- Guarantee coroutine safety: an instance must hold zero per-request state. Build request-scoped data (headers, buffers) as locals and pass them through; two concurrent calls on one instance must never race. Grep for instance properties written outside the constructor — each one is a suspect.
- Mark secrets `#[\SensitiveParameter]`. Optional dependencies default in the signature (`?ClientInterface $client = null`) and resolve in the constructor body.
- Prefer removals that fail loudly. Deleting a method breaks callers at call time; changing the *meaning* of a same-shaped signature (e.g. a `string` param switching from file path to raw data) corrupts silently. When semantics must change, change the method name too, or delete the old name.

## Surface area

- An abstract base class may only demand what every implementation can honestly provide. A method one adapter stubs with `-1`/no-op belongs on the concrete class, not the contract. Same test for return shapes: if two adapters return incompatible structures from the "same" method, it is not one abstraction — drop it from the base.
- One flow per concern. Two parallel method families differing only in input form (file path vs data) should collapse to one primitive; hoist the shared composition (`prepare → chunk → finalize`) into the base as a concrete method.
- Cross-cutting behavior composes via decorators and injected dependencies, never baked into adapters: telemetry is a decorator, retries are a client decorator with a domain `Strategy`, transport is an injected PSR-18 client. Never ship two mechanisms for the same concern — pick the composable one and delete the other.
- Enums over string constants for closed, library-controlled sets (device types, ACLs). Keep plain strings/constants for open sets that outpace releases (provider regions) or accept custom values.
- Delete metadata that code doesn't consume: human-prose `getName()`/`getDescription()` accessors, dead parameters, unused constants. Reuse platform types (`Utopia\Psr7\Method`) instead of redeclaring them.
- Internal wire formats deserve small `final readonly` value objects with honest narrowing, not `stdClass` juggling.

## Dependencies

- Audit `composer.json` against actual usage (`grep` each dep and each `ext-*`) — absorbed packages carry fossils.
- Depend on PSR interfaces at the seam; default to the utopia implementation (`utopia-php/client`, `utopia-php/psr7`) in the constructor body. Match transport defaults to the old behavior deliberately (e.g. no request timeout for unbounded uploads) — a client's own defaults are tuned for RPC, not file transfer.
- Sibling deps use Packagist constraints, never path repositories. Run `bin/monorepo validate` and regenerate the root dependency graph (`bin/monorepo graph`) after touching a manifest.

## Static analysis and guards

- New or redesigned packages pin `phpstan.neon` at `level: max` (include the root baseline) and extend the root `rector.php` with the stable prepared sets (`typeDeclarationDocblocks`, `privatization`, `instanceOf`, `rectorPreset`). Skip `naming`/`codingStyle` — they fight pint.
- Fix analysis errors by improving the design (typed response objects, shaped `@phpstan-type` aliases for by-ref arrays, runtime narrowing), never with `@phpstan-ignore` or silencing casts.
- A runtime guard beats a docblock type: if invalid input can corrupt data (`$chunkSize <= 0`), throw at runtime and drop the `positive-int` annotation — PHPStan flags guard-plus-annotation as dead code, and the guard is the contract callers actually get. The throw doubles as type narrowing downstream.
- Rector caches aggressively: after config changes, run `vendor/bin/rector process --clear-cache` locally before trusting `bin/monorepo check` — CI runs cold and will find what your cache hides.

## Tests

- Keep the tier contract: `composer test` = unit on bare host; `composer test:e2e` = against the package's compose services. Unit-test transport seams with scripted stubs that implement the *full* adapter interface, so decorators (retry, telemetry) are exercised for real rather than simulated.
- When a decorator or strategy reads a stream, verify re-readability in a test — a consumed body is a silent correctness bug.
- PHPUnit 12 assertions (`assertIsArray`, `fail(): never`) narrow types natively under PHPStan; prefer them over hand-rolled `if (!is_array(...))` blocks in tests.

## Releasing a major

- Releases are tags shaped `<package>/<semver>`; check existing tags before naming the version — branch names lie.
- README carries an "Upgrading from N.x" section mapping every removed API to its replacement, one bullet per break. Docs are Vale-linted (`vale README.md docs`): sentence-case headings, no weasel words, backtick code identifiers.
- Verify against live services before shipping protocol changes (the e2e MinIO suite caught nothing precisely because the SigV4 rewrite signed exactly what it sent — that is the standard: sign/send/store exactly one representation).
