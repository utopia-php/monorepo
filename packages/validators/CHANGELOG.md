# Changelog

All notable changes to `utopia-php/validators` are documented in this file.

## 0.4.0

### URL validator — OAuth2 redirect-URI transport policy

The `URL` validator can now express an OAuth2 redirect-URI transport policy
without a downstream custom validator.

#### Added

- New optional constructor parameter `bool $httpLoopbackOnly = false` (kept last
  in the signature). When enabled, values using the `http` scheme (case-insensitive)
  are valid only when the host is a loopback literal (`localhost`, `127.0.0.1`, or
  `[::1]`); `https`, other allowed schemes, and private-use schemes are unaffected
  (RFC 8252 §7.3). `getDescription()` notes the restriction when the flag is on.

#### Changed

- Private-use scheme URIs (e.g. `com.example.app:/oauth`) now bypass the
  `allowedSchemes` allowlist when `allowPrivateUseSchemes` is enabled. The
  allowlist governs standard (authority-bearing) URLs only (RFC 8252 §7.1). This
  fixes the previously unusable combination of `allowedSchemes` +
  `allowPrivateUseSchemes`.

Both changes are backward compatible: `httpLoopbackOnly` defaults to `false`, and
the allowlist change only affects the previously-broken
`allowedSchemes` + `allowPrivateUseSchemes` combination.
