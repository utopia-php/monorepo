# Changelog

## 0.1.0

- Added in-process IP reputation lookups against a Verdict MMDB.
- Added typed `Record` results with accessors. `getVerdict()` returns a
  `Verdict::*` string (`clean`, `low`, `suspicious`, `block`).
- Database search runs on the first field read, not on `get()`.
- Failed or missing lookups return `clean` so callers can fail open.
