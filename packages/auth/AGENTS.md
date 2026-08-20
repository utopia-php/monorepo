# Utopia Auth Agent Notes

Use the package documentation as the source of truth before changing public
behavior or examples:

- [README.md](README.md) for the feature overview and documentation index.
- [docs/oauth2.md](docs/oauth2.md) for OAuth2 and OpenID Connect token examples,
  resource indicators, prompts, pushed authorization request URIs, and client
  adapters.
- [docs/jwt.md](docs/jwt.md) for generic JWS/JWT verification behavior and
  claim/header enum references.
- [docs/hashing.md](docs/hashing.md), [docs/proofs.md](docs/proofs.md),
  [docs/store.md](docs/store.md), and [docs/validators.md](docs/validators.md)
  for hashing, proofs, the data store, and input validators.

Keep examples and helper docs close to the protocol or primitive they describe.
When adding OAuth2 or OpenID Connect helpers, update `docs/oauth2.md` rather than
expanding `docs/jwt.md`. Client adapters live in `Utopia\Auth\OAuth2\Provider`
and `Utopia\Auth\OAuth2\Providers`; protocol value objects stay in
`Utopia\Auth\OAuth2` (PAR, prompts, resource indicators).
