# Authentication Boundary

Status: first-release production foundation

Mediarama uses Symfony Security as the production HTTP authentication boundary while keeping domain/application services independent of the transport.

## First-release strategy

The first release uses a stateful browser session and form login.

- identity source: existing PostgreSQL `users` table
- login identifier: `username`
- password verification: Symfony PasswordHasher through `form_login`
- user provider: DBAL, not a second ORM user model
- production actor: Symfony Security token/session
- application boundary: `CurrentUser` still exposes only the authenticated Mediarama UUID
- authorization: collection/resource ACL services remain separate from authentication

API tokens, OAuth/OIDC and social login are deliberately deferred. They can be added later without changing the application-facing `CurrentUser` contract.

## Account status

Only `status = active` may authenticate normally.

Imported Coppermine accounts with `password_reset_required` and inactive accounts are rejected by the firewall user checker. Security errors remain generic so the login response does not reveal whether a username exists or which status it has.

Session refresh also compares the account status. If an active account becomes inactive or password-reset-required while a session exists, that session is no longer accepted as the same security user.

## Passwords and sessions

Mediarama does not reuse Coppermine password hashes.

The Symfony security user reads `users.password_hash` only for authentication. Before the user object is serialized into the session, that hash is replaced by a `crc32c` digest as supported by Symfony 7.4. This preserves password-change session invalidation without storing the real password hash in session data.

Symfony may opportunistically upgrade a successfully verified password hash through the DBAL user provider.

Session cookies are configured with:

- `HttpOnly`
- `SameSite=Lax`
- `Secure=auto`

Production must be served over HTTPS. If TLS terminates at a reverse proxy, the deployment must configure trusted proxy/scheme forwarding correctly so Symfony sees the request as HTTPS.

The current native session handler is suitable for a single application instance. Multi-instance deployments must deliberately provide shared session storage or an equivalent deployment strategy.

## CSRF

Login uses Symfony form-login CSRF validation.

Logout also has CSRF validation enabled. Templates generate the firewall-aware logout URL through Symfony's `logout_path()` helper.

Unsafe session-authenticated upload API requests require a second CSRF boundary:

1. the authenticated client requests `GET /api/auth/csrf`;
2. the response returns a session-bound `upload_token`;
3. `POST`/`PUT` requests under `/api/uploads` send it as `X-CSRF-Token`;
4. a missing or invalid token is rejected with JSON HTTP 403 before the upload controller runs.

The token endpoint itself requires `ROLE_USER` in production, is non-cacheable and is not indexable.

## HTTP behavior

- `/login` is public and non-indexable.
- `/api/uploads...` requires `ROLE_USER` in production.
- anonymous protected API requests receive JSON `401 authentication_required`.
- protected browser requests are redirected to the login page.
- public gallery and public search routes stay anonymous subject to their own visibility/privacy rules.

## Development/test actor

`X-Mediarama-User` remains a local development/test helper only.

`DevelopmentActorSubscriber` ignores it outside `dev` and `test`, and `RequestCurrentUser` also refuses the request-attribute fallback outside those environments. Production therefore never trusts a caller-supplied UUID header.

## Verification

CI starts the actual application with `APP_ENV=prod` against PostgreSQL and verifies:

- anonymous create/status/chunk/complete/finalize upload routes -> 401
- forged `X-Mediarama-User` in production -> 401
- login without CSRF does not establish an authenticated session
- active username/password login establishes a session
- authenticated upload without the API CSRF token -> 403
- authenticated upload with the API CSRF token succeeds
- authenticated upload session is owned by the logged-in Mediarama user
- inactive and `password_reset_required` identities cannot authenticate
- CSRF-protected logout invalidates the session
- changing an authenticated password invalidates the existing session
- changing an authenticated account from active to inactive invalidates further authenticated API access
