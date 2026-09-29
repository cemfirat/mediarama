# Coppermine 1.6 vs 1.7 identity/authentication comparison

Status: **verified targeted comparison**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current `cpg1.6.x/develop`
- `cpg1.7.x/main`
- current 1.6 bridge/login/register maintenance history

## Core conclusion

1.7 does **not** introduce a new identity model.

The same fundamental concepts remain:

- local Coppermine users/groups;
- optional external bridge authority;
- local DB-backed sessions;
- username/email login policy;
- active/inactive accounts;
- registration/email/admin activation;
- primary + additional groups;
- group-derived capabilities.

Most identity changes are maintenance, PHP compatibility or bootstrap refactoring rather than a redesigned authentication subsystem.

## Files checked

### Byte-identical in the current trees

- `groupmgr.php`
- `banning.php`
- `profile.php`

### Changed but behaviorally very close

- `login.php`
- `register.php`
- `usermgr.php`
- `bridge/udb_base.inc.php`
- `bridge/coppermine.inc.php`
- SMF bridge files

## Login

The checked 1.7 login difference is minor:

- 1.7 safely defaults the selected user language when `$USER['lang']` is absent before persisting it.

The broader login model remains the same.

Local Coppermine login can accept:

- username;
- email;
- either, depending on `login_method`.

Successful local login:

- validates account/password;
- invokes the `authorize_user` plugin action;
- updates last visit;
- upgrades an older unsalted password representation when appropriate;
- updates the DB-backed session;
- optionally marks "remember me".

## Password storage

Current 1.6 user rows contain:

- `user_password`
- `user_password_salt`
- `user_password_hash_algorithm`
- `user_password_iterations`

Historical Coppermine updates also contain compatibility paths for older MD5/plain-era credentials and opportunistic upgrade to the newer hash representation.

### Mediarama migration decision

The current Mediarama importer intentionally **does not reuse legacy password hashes**.

That is the correct default.

Imported active local users become:

- `password_reset_required`

Inactive source users remain:

- `inactive`

This avoids carrying forward historical password algorithms and removes the need for a permanent legacy-password verifier.

## Important importer preflight gap: email uniqueness

Coppermine can be configured to allow duplicate email addresses.

Mediarama currently has:

- unique username;
- unique non-null email.

Therefore a source gallery with duplicate non-empty emails can fail the current identity importer.

This must be handled explicitly before identity migration is production-ready.

Required preflight:

- duplicate usernames — source schema normally prevents this, but verify;
- duplicate non-empty emails;
- invalid/non-normalizable emails;
- empty emails;
- bridged identity mode.

Possible migration policy for duplicate email must be explicit. Do not silently rewrite user email addresses without reporting it.

## Registration

1.7 retains the same mature local registration model:

- optional registration;
- optional global registration password;
- disclaimer/terms;
- CAPTCHA;
- email verification;
- admin activation;
- admin notification;
- personal album creation.

Most differences in the checked `register.php` are syntax/markup/PHP maintenance.

There is no new identity architecture to copy.

## User manager

Current 1.6 continued receiving later PHP compatibility fixes, including a 2023 PHP 8.2 user/language manager correction.

This again means current 1.6 is the better maintenance reference.

## Bridge architecture

Both versions retain the bridge abstraction in which an external application can become the identity authority.

The practical migration warning remains:

> numeric Coppermine user IDs are not sufficient evidence of portable local identity when bridging is enabled.

Mediarama import preflight must fail or require an explicit identity strategy for bridged installations.

## Current 1.6 bridge maintenance beyond 1.7

Current 1.6 received later compatibility fixes after 1.7 development stopped, including:

- SMF 2.0.16 compatibility;
- SMF 2.1 updates;
- PHP 8 fixes for SMF bridge code;
- corrected SMF 2.1 behavior;
- login authorization plugin hook work.

These changes reinforce the use of current 1.6 as the operational reference.

## 1.7 bridge code quality finding

The checked 1.7 local Coppermine bridge assigns:

`$this->group_overrride`

with three `r` characters.

Current 1.6 assigns:

`$this->group_override`

The repository search did not find meaningful use of `group_override` elsewhere in the checked branches, so this should be recorded as a code-quality divergence rather than claimed as a proven authorization failure.

It nevertheless illustrates the broader point: the 1.7 branch is experimental/stale, not a canonical "newer and safer" source.

## 1.7 bootstrap separation

1.7 introduces `include/cpg.inc.php`, which performs a large portion of non-presentation bootstrap:

- config/database;
- language;
- plugins;
- cookie consent;
- bridge loading;
- authentication;
- admin/user mode;
- base authorization/debug state.

Then `init.inc.php` adds full theme/page initialization.

This is one of the better 1.7 architectural ideas:

> non-page/API-like requests should be able to bootstrap application state without loading the full presentation stack.

Mediarama already achieves this more cleanly with its framework/application-service layering.

## Session model

The local identity layer remains DB-session based:

- browser cookie carries a session/client value;
- DB stores the hashed session identifier;
- normal session lifetime is about one hour;
- remember-me lifetime is two weeks;
- cleanup is opportunistic and rate-limited.

Mediarama should not migrate any active Coppermine session or remember-me state.

## Authorization plugin hook

Current 1.6 includes the `authorize_user` plugin action in local login.

That means a real Coppermine installation can have plugin-defined login authorization behavior beyond core tables/config.

Migration preflight must therefore inventory installed plugins before claiming identity-policy equivalence.

## Mediarama architecture consequence

Keep the existing direction:

- local user row independent from external identities;
- explicit external identity table/provider model later;
- password reset/invite after Coppermine local-user import;
- no session migration;
- resource-scoped authorization separate from identity;
- plugin/extension authorization hooks only through typed policy extension points.

## Remaining identity migration work

Before identity migration can be signed off:

- implement duplicate-email preflight/policy;
- capture six source profile fields + configured labels;
- handle bridged installs explicitly;
- classify active account bans;
- import remaining group quota/approval semantics;
- test inactive/unverified accounts;
- test user galleries;
- test primary + multiple additional groups;
- test source users without usable email;
- test real galleries with historical password-hash variants.

## 1.6 vs 1.7 conclusion

There is no compelling 1.7 identity subsystem to transplant.

Use:

- current 1.6 for maintained behavior/security lessons;
- 1.7's bootstrap separation as conceptual input;
- Mediarama's independent modern identity/authorization design as the target.
