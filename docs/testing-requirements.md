# Testing Requirements

These requirements translate the vendored FIG specifications and production-readiness concerns into local test coverage.

Source specs:

- `docs/psr/PSR-7-http-message.md`
- `docs/psr/PSR-17-http-factory.md`
- `docs/psr/PSR-18-http-client.md`
- `docs/rfc/RFC-2046-media-types.md`
- `docs/rfc/RFC-7578-multipart-form-data.md`

## PSR-7 Message Coverage

- Messages are immutable: every `with*()` method returns a changed copy and leaves the original unchanged.
- Header lookup is case-insensitive, but `getHeaders()` preserves the original header case.
- `withHeader()` replaces existing values regardless of case.
- `withAddedHeader()` appends values regardless of case.
- `withoutHeader()` removes values regardless of case.
- Header values are normalized to string arrays.
- `Host` is derived from the URI when creating or replacing request URIs unless preservation rules prevent it.
- `withUri($uri, true)` preserves a populated `Host` header.
- `withUri($uri, true)` still sets `Host` when no host header exists and the new URI has a host.
- Request targets include path and query and default to `/`.
- URI authority includes user info, host, and non-default ports.
- URI default ports are omitted from `getPort()` and string output.
- Stream state changes after `detach()`/`close()` match PSR-7 expectations.

## PSR-17 Factory Coverage

- Dedicated factories implement their respective PSR-17 interfaces.
- Request factory accepts string and `UriInterface` values.
- Response factory rejects invalid status codes through the response object.
- Stream factory creates streams from strings, files, and resources.

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

## Multipart Coverage

- Multipart request builder creates stable per-part headers and terminal boundaries.
- Multipart request builder supports scalar fields, file/body parts, filenames, content types, and custom per-part headers.
- Multipart request builder emits CRLF-delimited part headers and bodies.
- Multipart request builder escapes quoted `Content-Disposition` parameter values for field names and filenames.
- Multipart request builder supports repeated field names by accepting multiple explicit parts with the same name.
- Multipart response decoder parses quoted and unquoted boundaries.
- Multipart response decoder treats only delimiter lines as multipart boundaries and does not split on boundary-like text inside part content.
- Multipart response decoder ignores preamble and epilogue content.
- Multipart response decoder preserves part ordering.
- Multipart response decoder preserves repeated field names as separate ordered parts.
- Multipart response decoder supports quoted and token `Content-Disposition` parameter values.
- Multipart response decoder preserves duplicate per-part headers.
- Multipart response parts expose name, filename, content type, headers, and body.
- Multipart response decoder rejects missing boundaries.
- Multipart response decoder preserves CRLF-like bytes inside part bodies.
