# Coppermine sessions, cookies and background-work audit

Status: **verified first-pass runtime-state audit**
Date: 2026-09-25
Tracking: #11

Primary source: current Coppermine 1.6 initialization, local user bridge/session implementation, cookie call sites and repository tree.

## Local session model

Coppermine's standalone user database layer stores sessions in the core `sessions` table.

The stored fields include:

- hashed session identifier;
- user ID;
- last activity time;
- remember-me flag.

The browser holds a separate session/client cookie and the DB row stores a hash derived from the session/client identifiers.

## Session lifetime and cleanup

The local bridge code treats ordinary sessions as approximately one hour old for cleanup purposes.

"Remember me" sessions have a longer lifetime.

There is no evidence in the current core of a dedicated scheduler being required for this cleanup.

Instead, session cleanup is performed opportunistically during normal session handling and rate-limited with the `session_cleanup` configuration timestamp so cleanup runs at most periodically.

### Mediarama implication

Mediarama should not depend on request-time opportunistic cleanup for every lifecycle concern.

Use explicit mechanisms for:

- session expiry handled by the security/session backend;
- periodic cleanup jobs where necessary;
- upload-session cleanup;
- export expiration;
- stale processing/job cleanup.

## Cookie-consent gating

Current 1.6 has a `cookies_need_consent` setting.

`CPG_COOKIES_ALLOWED` is false when consent is required and the consent marker cookie is absent.

Multiple cookie-writing paths check this flag.

Confirmed examples include:

- favorites cookie;
- session/login cookies;
- legacy user/profile data cookie.

This is useful product knowledge: cookie consent can affect functional state such as favorites and persistent login, not just analytics.

## Important serialized cookie state

Several legacy cookie paths use PHP serialization.

Confirmed examples include:

- user/profile data cookie: base64 + serialized structure;
- favorites cookie: base64 + serialized PID list;
- album-password cookie: serialized album-password data.

### Security/migration rule

Mediarama must never import or trust these old cookies as authenticated state.

If any browser-assisted migration is ever implemented:

- parse only explicitly expected scalar/array shapes;
- never allow PHP object deserialization;
- do not transfer sessions;
- do not transfer old album passwords;
- re-authorize every resource in Mediarama.

## Album-password cookie

Coppermine can remember password-protected album access in a client cookie.

That reinforces two migration conclusions:

- source album password hashes/cookies are runtime authorization state, not portable credentials;
- imported protected collections must remain fail-closed until a new Mediarama password/access mechanism is established.

## Favorites cookie

Anonymous favorites can live only in the client cookie.

Authenticated favorites are additionally stored server-side.

Therefore a normal server-side database migration can preserve authenticated favorites but cannot automatically discover every anonymous visitor's local favorite set.

## Dedicated background/CLI architecture

A repository/source search for conventional core:

- cron entry points;
- CLI commands;
- background queue/worker infrastructure;

did not identify a first-class Coppermine job system.

Heavy/long-running operations are generally implemented as:

- synchronous web requests;
- progressive/batched web-admin tools;
- opportunistic cleanup during requests.

This is a material architectural difference from Mediarama's Messenger/job direction.

## Cache model

The audit has not found a general application cache subsystem comparable to a modern framework cache/Redis layer.

Some data is effectively cached in specialized persistence:

- EXIF parsed data in the `exif` table;
- sessions in DB;
- generated thumbnails/intermediates on filesystem;
- configuration loaded from DB;
- client cookie state.

These are specialized caches/state, not a unified cache architecture.

## Mediarama direction

Use explicit categories:

- canonical data;
- user/session/security state;
- regeneratable derivatives;
- metadata extraction snapshot/cache;
- query/application cache;
- asynchronous jobs.

Each category should have its own invalidation/retention semantics.

## Migration classification

Do not migrate:

- active Coppermine sessions;
- session IDs;
- login cookies;
- album-password cookies;
- CSRF/form tokens;
- stale runtime cleanup timestamps.

Potentially migrate/transform:

- authenticated favorites;
- meaningful user preferences, once identified;
- source policy indicating whether guest access/cookies were expected.

## Remaining runtime-state audit

Still worth checking before closure:

- exact remember-me lifetime/configuration;
- guest-token cleanup;
- e-card/contact transient state;
- plugin-owned scheduled/request-time maintenance patterns;
- any plugin that implements its own cron/queue behavior.

Core architecture, however, clearly does not provide a generalized background-job system.
