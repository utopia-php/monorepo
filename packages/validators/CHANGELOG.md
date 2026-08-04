# Changelog

All notable changes to `utopia-php/validators` are documented in this file.

## 0.3.1

### URL validator — OAuth2 secure-redirect transport policy

#### Added

- New optional constructor parameter `bool $httpsOrLoopback = false` (kept last
  in the signature). When enabled, a standard (authority-bearing, non-private-use)
  URL is valid only if its scheme is `https` on any host, or `http` on a loopback
  host (`localhost`, `127.0.0.1`, or `[::1]`); every other standard scheme and any
  routable `http` host is rejected (RFC 8252 §7.3). Private-use scheme URIs
  (governed by `allowPrivateUseSchemes`) are exempt. The flag is self-contained and
  independent of `allowedSchemes` — when both are set, a value must satisfy both.
  `getDescription()` reflects the restriction when the flag is on.

The change is backward compatible: `httpsOrLoopback` defaults to `false`, so
existing callers are unaffected, and the behavior of `allowedSchemes` and
`allowPrivateUseSchemes` (including how `allowedSchemes` restricts which
private-use schemes are accepted) is unchanged.
