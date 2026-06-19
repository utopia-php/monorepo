# Changelog

## 0.1.0

### Added

- Initial release: a Swoole-native MySQL binlog replication reader.
  - `Replication` — connect, `COM_BINLOG_DUMP_GTID`, blocking `getChanges()`
    generator yielding `Change` objects; GTID checkpoint advanced on commit;
    schema filtering; optional TLS.
  - `Connection` — coroutine socket framing with `caching_sha2_password`
    authentication (fast-auth, RSA full-auth, `mysql_native_password` fallback)
    and STARTTLS.
  - `EventParser` — TABLE_MAP + WRITE/UPDATE/DELETE_ROWS decoding; column names
    from FULL row metadata; JSON values skipped.
  - `GtidSet`, `BinaryReader`, `Change`, `Exception`.
