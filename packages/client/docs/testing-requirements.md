# Testing Requirements

These requirements translate the vendored FIG specification and production-readiness concerns into local test coverage.

Source specs:

- `docs/psr/PSR-18-http-client.md`

## PSR-18 Client Coverage

- Clients return 4xx/5xx responses without throwing.
- Invalid requests throw `RequestExceptionInterface`.
- Network failures, including timeouts, throw `NetworkExceptionInterface`.
- Clients collapse interim 1xx response headers and expose only the final response.

## Timeout Coverage

- `Utopia\Client` timeout helpers are immutable.
- cURL adapter maps timeout seconds to `CURLOPT_TIMEOUT_MS`.
- cURL adapter maps connect timeout seconds to `CURLOPT_CONNECTTIMEOUT_MS`.
- Swoole adapter maps timeout seconds to `timeout`.
- Swoole adapter maps connect timeout seconds to `connect_timeout`.
- Invalid timeout values throw `ValueError`.
