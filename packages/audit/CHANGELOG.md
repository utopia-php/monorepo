# Changelog

All notable changes to `utopia-php/audit` are documented in this file.

## Unreleased

### ClickHouse adapter — origin column

The ClickHouse adapter now stores the `Origin` request header. `hostname` records
the host that *served* a request; `origin` records who *made* it, which is what an
audit trail needs to tell a console click apart from a script replaying a session
cookie. Browsers always send the header, so a null `origin` identifies a caller
that was not a browser.

#### Added

- `Log::getOrigin()` getter for ClickHouse-backed log reads.

#### ClickHouse schema changes

- Column `origin` `Nullable(String)` — the `Origin` request header (e.g.
  `https://cloud.appwrite.io`); optional. Deliberately *not* `LowCardinality`:
  origins are per-project and the table is shared across every tenant, so the
  distinct-value count is unbounded (same reasoning as `autonomousSystemNumber`).
- Index `_key_origin` — bloom-filter index on the `origin` column.

The column is optional (`required = false`) so `createBatch()` never throws when a
caller omits it.

### ClickHouse adapter — `setup()` reconciles columns on existing tables

#### Changed

- `setup()` now adds schema columns that an existing table is missing, via
  `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`. Previously it only issued
  `CREATE TABLE IF NOT EXISTS`, so a release that added a column never reached a
  table that already existed — while `createBatch()` names every schema column in
  its `INSERT`, so the first write after such a release failed instead of
  degrading. Every column added since the ClickHouse adapter shipped (`sdk`,
  `sdkVersion`, the premium geo columns, the user-agent columns and now `origin`)
  is reconciled by this, so the out-of-band `ALTER TABLE` those releases asked for
  is no longer needed.

`setup()` reads `system.columns` once and issues an `ALTER` only per missing
column, so the steady-state cost is a single extra query. Adding a nullable column
with no `DEFAULT` is metadata-only: parts written before the `ALTER` read the new
column back as `NULL` and no data is rewritten.

Only columns are reconciled, not indexes. A skip index added to a populated table
covers new parts only until it is materialized, so a missing index costs read
performance where a missing column costs every write.

### ClickHouse adapter — migrated to the utopia-php/query 0.6 builder

#### Changed

- `utopia-php/query` bumped from `0.1.*` to `0.6.*` (locked at 0.6.0).
- `setup()` builds its DDL through `Utopia\Query\Schema\ClickHouse` instead of
  hand-assembled SQL. Column types, `LowCardinality(...)` / `Nullable(...)`
  wrapping, bloom-filter indexes, engine, `ORDER BY`, `PARTITION BY` and
  `SETTINGS` are all emitted by the schema builder. The retention `MODIFY TTL`
  / `REMOVE TTL` statements are unchanged.
- `find()`, `count()`, `getById()`, `createBatch()` and `cleanup()` build their
  SQL through `Utopia\Query\Builder\ClickHouse`. Positional bindings are
  rewritten to typed `{paramN:Type}` ClickHouse placeholders from a column →
  type map derived from `getAttributes()`.
- `createBatch()` uses `Builder\ClickHouse::bulkInsert(Format::JSONEachRow, …)`
  to emit the `INSERT … FORMAT JSONEachRow` envelope and serialize the body.
- `Query::getMethod()` now returns the `Utopia\Query\Method` enum (upstream
  0.3 change). `Utopia\Audit\Query` continues to expose the legacy `TYPE_*`
  string constants, which map to the same string values.

Filter semantics are unchanged: `contains` / `notContains` remain substring
matches (now compiled to ClickHouse `position(col, ?) > 0` / `= 0` rather than
`LIKE '%needle%'`, which also removes the need for wildcard escaping).

## 2.9.0

### ClickHouse adapter — user-agent columns

The ClickHouse adapter now stores the parsed user-agent OS / client / device
dimensions as dedicated optional columns (mirrors the usage events schema).

#### Added

- `Log` getters for ClickHouse-backed reads: `getOsCode()`, `getOsName()`,
  `getOsVersion()`, `getClientType()`, `getClientCode()`, `getClientName()`,
  `getClientVersion()`, `getClientEngine()`, `getClientEngineVersion()`,
  `getDeviceName()`, `getDeviceBrand()`, `getDeviceModel()`.

#### ClickHouse schema changes

- `LowCardinality(Nullable(String))` — `osCode`, `osName`, `clientType`,
  `clientCode`, `clientName`, `clientEngine`, `deviceName`, `deviceBrand`
  (bounded name/code/type dimensions).
- `Nullable(String)` — `osVersion`, `clientVersion`, `clientEngineVersion`,
  `deviceModel` (high-cardinality version/model strings, mirroring `sdkVersion`).

All columns are optional (`required = false`) so `createBatch()` never throws when a
caller omits them. Newly created tables include the columns automatically via `setup()`.
`setup()` only issues `CREATE TABLE IF NOT EXISTS`, so **existing** tables do not gain the
columns automatically — apply them with an `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`
migration.

## 2.7.0

### ClickHouse adapter — SDK columns

The ClickHouse adapter now stores two additional optional columns capturing the
SDK that produced an audit event:

#### Added

- `Log::getSdk()` and `Log::getSdkVersion()` getters for ClickHouse-backed log reads.

#### ClickHouse schema changes

- Column `sdk` `LowCardinality(Nullable(String))` — SDK name (e.g. `web`, `flutter`,
  `console`, `cli`); low-cardinality, optional.
- Column `sdkVersion` `Nullable(String)` — SDK version (e.g. `14.0.0`); high-cardinality,
  optional.
- Index `_key_sdk` — bloom-filter index on the `sdk` column.

Both columns are optional (`required = false`) so `createBatch()` never throws when a
caller omits them. Newly created tables include the columns automatically via `setup()`.
`setup()` only issues `CREATE TABLE IF NOT EXISTS`, so **existing** tables do not gain the
columns automatically — apply them with an `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`
migration.

## 2.4.0

### ClickHouse adapter — actor terminology

The ClickHouse adapter now stores its principal columns under "actor" terminology:
`actorId`, `actorType`, `actorInternalId`. The shared SQL base, the Database adapter,
and the public `Audit` API are unchanged — Database-backed audit logs continue to use
`userId`.

This is a non-breaking change for callers of the public API. `Audit::log($userId, ...)`,
`Audit::getLogsByUser(...)`, `Audit::countLogsByUser(...)`, and the equivalent
`*ByUserAndEvents` methods all keep their original signatures. The ClickHouse adapter
translates the legacy `userId` array key and `Query::equal('userId', ...)` filter
internally to the renamed `actorId` column.

#### Added

- `Log::getActorId()`, `Log::getActorType()`, `Log::getActorInternalId()` getters for
  ClickHouse-backed log reads.
- `Log` instances returned by the ClickHouse adapter expose both `actorId` / `actorType`
  / `actorInternalId` (canonical) and `userId` / `userType` / `userInternalId` (legacy
  mirror) attribute keys so existing code paths continue to work.

#### ClickHouse schema changes

- Column `userId` → `actorId`
- Column `userType` → `actorType`
- Column `userInternalId` → `actorInternalId`
- Index `idx_userId_event` → `idx_actorId_event`
- Index `_key_user_type` → `_key_actor_type`
- Index `_key_user_internal_id` → `_key_actor_internal_id`
- Index `_key_user_internal_and_event` → `_key_actor_internal_and_event`

#### Migration

ClickHouse audit tables will be recreated by `setup()` with the new column names.
Existing ClickHouse audit data is not preserved automatically — this is acceptable
because the activity-events surface backed by this schema is not yet in public use.
If preservation is needed, run `ALTER TABLE ... RENAME COLUMN` for each renamed
column before redeploying.

No migration is required for Database-backed audit logs. The Database adapter
continues to write and read `userId` columns and indexes unchanged.

## 2.3.2 and earlier

See git history.
