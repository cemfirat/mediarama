# First-run administrator bootstrap

Status: first-release production foundation  
Date: 2026-09-27

Mediarama does not expose a generic permanent "create administrator" endpoint. A new installation has one explicit first-run bootstrap boundary that exists only while the persisted platform setup state is `pending`.

## Persisted state

`platform_settings` owns the singleton setup state:

- `setup_status = pending | completed`;
- `setup_completed_at`;
- `setup_completed_by` (nullable FK to the administrator user);
- `setup_completed_via = migration | browser | cli | existing_admin`.

A completed setup remains completed even if the original administrator account is later deleted; the FK may become null, while the timestamp and completion method remain as the durable audit record.

The bootstrap transaction locks the singleton platform-settings row with `FOR UPDATE`. Concurrent first-run attempts therefore serialize on one database row and cannot create two first administrators.

## Browser setup

The browser route is `/setup`.

It is public only because no authenticated identity can exist on a genuinely new installation. That does **not** make possession of the URL sufficient authority to initialize the system.

Browser setup requires all of the following:

1. the database setup state is still `pending`;
2. a deployment-configured `MEDIARAMA_SETUP_TOKEN` of at least 32 characters exists;
3. the caller supplies that token;
4. the request carries a valid Symfony CSRF token;
5. account and profile validation succeeds.

The configured setup token is compared in constant time, is never written to the database, is never rendered back into HTML, and is not accepted in URLs. The persisted completion state makes the browser token one-time in effect: after setup completes, POST `/setup` fails closed even if the environment variable still exists.

Production deployments should generate a high-entropy token on the server and provide it to the installer through a separate trusted channel. For example:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Do not commit the generated value.

If `MEDIARAMA_SETUP_TOKEN` is empty or shorter than 32 characters, browser bootstrap remains locked and the server-side CLI path must be used.

## First administrator

A fresh installation creates:

- one active Mediarama user;
- one system group with slug `mediarama-administrators`;
- the existing stable `system.admin` permission on that group;
- normal `user_groups` membership for the new user.

No separate "superuser" flag is introduced. After bootstrap, administration continues to use the same group/capability model as the rest of Mediarama.

Passwords are hashed through Symfony's configured `UserPasswordHasherInterface`, the same password policy used by normal production authentication.

The bootstrap accepts no default password and the CLI intentionally has no `--password` command-line argument.

## Deployment profile

Bootstrap applies one of the existing publication presets atomically with administrator creation:

- `private_workspace` — recommended/default;
- `public_publishing`;
- `internal_isolated`.

The profile is configuration intent. `internal_isolated` does not create a firewall, VPN, private network or physical isolation.

If a stale pending state is reconciled against an already-active system administrator, Mediarama marks setup completed without changing that installation's existing publication profile.

## Existing and imported administrators

Mediarama must not create a second administrator merely because setup state is pending.

The bootstrap checks identities that already receive `system.admin` through normal group membership:

- **active administrator**: setup is reconciled as completed; credentials and publication settings are unchanged;
- **`password_reset_required` administrator**: entering the same username during bootstrap recovers that identity in place, assigns a new Mediarama password hash and activates it;
- **inactive administrator**: browser bootstrap does not silently reactivate it; server-side recovery requires the explicit `--recover-inactive` option.

This is especially important for Coppermine migration. Mediarama never reuses a legacy Coppermine password hash.

## Server-side bootstrap and recovery

When browser bootstrap is unavailable, use:

```bash
php bin/console mediarama:setup:bootstrap-admin <username>
```

Interactive use prompts for the password twice with hidden input.

For automation, pass the password on standard input:

```bash
printf '%s\n' "$ADMIN_PASSWORD" | php bin/console mediarama:setup:bootstrap-admin <username> --password-stdin
```

Do not put the password in the command line, shell history, logs or process arguments.

Supported options include:

- `--email=<address>`;
- `--profile=private_workspace|public_publishing|internal_isolated`;
- `--password-stdin`;
- `--recover-inactive` for deliberate server-side recovery of a matching inactive system administrator.

The CLI uses the same persisted setup-state guard as the browser flow. It is not a backdoor around completed setup.

## HTTP and privacy behavior

The setup page is returned with:

- `Cache-Control: private, no-store`;
- `X-Robots-Tag: noindex, nofollow`.

The setup token and administrator password are never intentionally returned in response bodies or command output.

After successful browser bootstrap, Symfony programmatically authenticates the new/recovered administrator and redirects into the protected admin area.

## Verification

Normal CI covers:

- fresh pending setup state;
- safe anonymous redirect into setup;
- hidden server-side setup token;
- invalid token rejection;
- CSRF-protected browser bootstrap;
- group-derived `system.admin`;
- password hashing and authenticated admin session;
- atomic Private workspace defaults;
- repeated browser/CLI bootstrap rejection;
- imported password-reset administrator recovery without duplicate identity;
- explicit inactive-account recovery;
- already-active administrator reconciliation without credential/profile mutation;
- absence of password/setup-token values in tested response and CLI output.
