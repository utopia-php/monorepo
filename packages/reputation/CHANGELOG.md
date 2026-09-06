# Changelog

## 0.1.0

- Added in-process IP reputation lookups against a Verdict MMDB.
- Added typed `Record` results with a closed `Verdict` set (`clean`, `low`,
  `suspicious`, `block`).
- Failed or missing lookups return `clean` so callers can fail open.
