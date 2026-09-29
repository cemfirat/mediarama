# Coppermine bans, brute-force protection and account-state audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #8, #11

Primary sources:

- current 1.6 `sql/schema.sql`
- `banning.php`
- `login.php`
- `include/init.inc.php`
- user deletion flow

## Ban table

Coppermine's `banned` table stores:

- `ban_id`
- optional `user_id`
- `user_name`
- `email`
- `ip_addr`
- optional `expiry`
- `brute_force`

The same table is used for two related but distinct concerns:

1. deliberate administrative bans;
2. temporary failed-login/brute-force state.

That distinction is essential for migration.

## Administrative bans

The admin ban manager can create/update a record by combinations of:

- user ID;
- user name;
- email;
- IP/pattern;
- expiry.

Normal runtime denial is primarily checked against:

- logged-in user ID;
- raw/client IP;
- header-derived IP;

and only rows with `brute_force = 0` are treated as active ordinary bans in the observed main bootstrap check.

The admin UI nevertheless carries username/email fields as part of the ban record and management/search surface.

## IP pattern behavior

Runtime uses SQL `LIKE` against the stored `ip_addr`.

Therefore source ban rows can represent patterns rather than only a literal address.

Current 1.6 later expanded relevant IP handling for IPv6 compatibility.

### Mediarama implication

Do not automatically copy old SQL-LIKE IP patterns into a modern network blocklist.

If IP banning is supported, use an explicit representation:

- exact IP;
- CIDR/network;
- expiry;
- reason;
- audit actor.

Reject ambiguous legacy patterns or require operator review.

## Failed-login / brute-force state

On failed login, Coppermine:

1. looks for an existing ban row for the request IP;
2. sets an expiry based on `login_expiry` minutes;
3. if a brute-force counter exists, decrements it;
4. otherwise inserts a row whose `brute_force` starts at `login_threshold`;
5. once the counter reaches the relevant state, the row participates in protection until expiry/reset under the legacy logic.

Defaults visible in admin configuration include:

- login threshold: 5;
- expiry: 10 minutes.

This is temporary authentication defense state, not a durable account ban.

## Expired-ban purge

If `purge_expired_bans` is enabled, the application deletes expired ban rows during normal request initialization.

This is request-triggered cleanup.

Mediarama should use explicit expiration semantics and scheduled cleanup, while authorization checks naturally ignore expired records.

## Account state is separate

Coppermine users also have:

- `user_active = YES/NO`;
- email verification/activation state;
- activation key.

Therefore the following are distinct source concepts:

- inactive/unactivated account;
- administratively banned account;
- temporary login lockout;
- network/IP ban.

Mediarama migration must not collapse them into one boolean.

## User deletion

When a user is deleted, Coppermine clears ban rows linked by `user_id`.

This shows that at least some ban records are considered account-associated lifecycle data.

## Bridged installations

Bans become more complicated when the gallery is bridged because the external application is the identity authority.

Migration preflight must not assume that a Coppermine `user_id` in a ban row maps to a portable local identity without bridge analysis.

## Migration classification

### Inactive user state

Classification: **Transform directly**.

Do not activate an inactive source account accidentally.

The current Mediarama identity importer already preserves inactivity.

### Explicit account-linked administrative ban

Classification: **Transform with review**.

A current, non-expired ban tied to a resolvable user may represent deliberate safety/moderation policy worth preserving.

Mediarama needs an explicit account-suspension/ban model before importing it.

### Brute-force rows

Classification: **Intentional omission of transient state**.

Do not migrate an old login-failure counter/temporary IP lockout into the new authentication system.

### IP/email/name bans without resolvable account

Classification: **Blocking review / usually omit by default**.

Reasons:

- privacy-sensitive;
- legacy matching semantics;
- stale network addresses;
- SQL-LIKE patterns;
- bridged identity ambiguity.

Preflight should report them, not silently grant or discard them.

## Recommended Mediarama model

Separate:

### Account status

- active;
- pending verification;
- disabled/suspended;
- deleted/anonymized.

### Security throttling

- rate-limit counters;
- credential-abuse detection;
- temporary lockouts;
- provider/infrastructure managed where appropriate.

### Moderation/security ban

If required:

- subject user/account;
- reason;
- created by;
- created/expiry timestamps;
- status;
- audit history.

### Network block

Only if product requirements justify it:

- CIDR/exact address;
- purpose;
- expiry;
- audit information;
- retention/privacy policy.

## Migration preflight

Report:

- total ban rows;
- active vs expired;
- `brute_force != 0` transient rows;
- rows linked to source users;
- orphan user IDs;
- rows with IP;
- rows with username/email but no user ID;
- wildcard/pattern-like IP entries;
- bridged installation status.

## Tests required

- inactive but not banned user;
- active user with explicit current ban;
- expired user ban;
- brute-force row;
- exact IPv4;
- IPv6;
- pattern-style IP;
- orphan user ID;
- bridged user ban.

## Product/security conclusion

Coppermine's ban table mixes durable moderation with temporary authentication defense.

Mediarama should **separate those concerns** and import only deliberate user/account restrictions once a target suspension model exists.

Old brute-force/network state should not become Mediarama authorization by accident.
