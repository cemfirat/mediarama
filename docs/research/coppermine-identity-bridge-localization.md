# Coppermine identity, bridging, bans and localization audit

Status: **active audit**
Date: 2026-09-25
Tracking: #11

## External identity bridging

Coppermine contains a formal bridge subsystem and bridge manager.

Current 1.6 bridge adapters include:

- MyBB
- Phorum
- phpBB3
- SMF 2.0
- SMF 2.1
- vBulletin 3
- XMB
- XOOPS
- Coppermine/local UDB base infrastructure

The bridge manager stores settings in the `bridge` key/value table and validates items such as:

- application/config paths;
- external URL/path assumptions;
- cookie prefixes;
- mandatory settings.

Source and language text also confirm recovery behavior for disabling a failed bridge using the standalone Coppermine administrator identity.

### Migration implication

A bridged gallery may not own the authoritative user credential store.

Therefore a Mediarama importer must not blindly treat every Coppermine user row as a complete portable identity.

Preflight should detect bridge configuration and require one of:

- explicit standalone/local identity migration;
- external identity mapping strategy;
- migration to a modern identity provider;
- documented loss/disablement of bridge-derived login.

This is an architecture/migration blocker until the bridge semantics are fully classified.

## Category-scoped album creation

The previously missed `categorymap` table maps `cid + group_id` and is actively used for authorization.

It controls which groups may create/manage public albums in specific categories and participates in album/category editing/deletion decisions.

This is **not the same thing as album view visibility**.

Mediarama therefore needs to distinguish at least:

- view collection;
- add media to collection;
- create collection under parent;
- edit/manage collection;
- possibly moderate/manage media in collection.

The current imported `collection.view` rules do not cover this source capability.

## Group semantics

Beyond the capabilities already mapped, Coppermine user groups persist:

- storage quota;
- public upload approval requirement;
- private upload approval requirement;
- access level;
- e-card capability.

These are not decorative values. They affect runtime behavior.

Mediarama must classify each:

- quota → likely migrate to quota policy;
- upload approval → moderation policy;
- access level → research exact semantics before mapping;
- e-card capability → likely obsolete if e-cards are intentionally omitted.

## Bans and brute-force state

The `banned` table can store:

- user ID;
- username;
- email;
- IP address;
- expiry;
- brute-force state.

Login code also updates brute-force ban state/expiry.

### Mediarama implication

Do not bulk-import network bans until the security/privacy design is settled.

However, silently dropping a deliberate active account ban may reactivate a user who was intentionally blocked.

The final migration policy must distinguish:

- account-level bans worth preserving;
- temporary brute-force lockouts that should not migrate;
- IP bans whose privacy/security value must be evaluated;
- expired bans that can be ignored.

## Language architecture

Coppermine has both language files and a `languages` database table containing:

- language ID;
- English/native/custom name;
- flag;
- abbreviation;
- availability;
- enabled state;
- completeness.

The language manager reconciles installed language files with DB definitions and lets administrators enable/disable languages and choose a default.

Runtime language selection can be rendered as a dropdown or flags.

English acts as a practical fallback source when a requested language variable is absent.

### Mediarama implication

Do not migrate Coppermine PHP language files.

Useful configuration semantics to preserve or reconsider:

- default locale;
- enabled locales;
- installation-specific display labels;
- fallback locale.

Mediarama should use a modern translation catalog system and locale negotiation rather than Coppermine-compatible PHP language globals.

### Verified migration mapping

Coppermine persists a user's preference as the language **ID** used for its PHP language file, not necessarily as an ISO locale. Core registration stores the configured `lang` value and login can later persist the selected `$USER['lang']` value into `users.user_language`.

Therefore copying `user_language` directly into Mediarama `users.locale` is incorrect for ordinary values such as `english` or `german`.

The migration now resolves:

`users.user_language → languages.lang_id → languages.abbr → users.locale`

Examples covered by CI:

- `english → en`
- `german → de`

The source `languages` table is required for migration inspection. A populated user language or configured default language that cannot resolve to an available row with a non-empty abbreviation blocks migration.

The source enabled-language list, custom display names and `language_autodetect` behavior are not copied as Mediarama runtime configuration. They describe Coppermine's UI/runtime locale selection rather than portable gallery content.


## Remaining identity audit

Still required:

- exact profile custom-field semantics;
- registration approval/activation flows;
- password/hash transition history;
- login-by-username/email configuration;
- anonymous/guest group behavior;
- access-level semantics;
- bridge group mapping;
- registration/profile restrictions while bridged;
- how plugin hooks can override authorization (`authorize_user`).


## Registration lifecycle

Coppermine's local-account registration has a substantial configuration surface.

Confirmed options include:

- allow/disable self-registration;
- optional global registration password;
- registration disclaimer/terms display;
- CAPTCHA;
- email verification requirement;
- administrator activation requirement;
- administrator email notification;
- creation of a personal user album on registration.

When email verification or administrator activation is required, a newly registered account is created inactive until the corresponding activation flow completes.

### Mediarama implication

The importer should preserve **account state**, not Coppermine's activation tokens or historical credential mechanics.

For a new Mediarama installation:

- migrated active local users should use the explicit password-reset/invitation flow already planned;
- inactive/unverified users should not be accidentally activated;
- old activation keys should not become Mediarama credentials;
- registration policy should be product configuration, not blindly copied as raw Coppermine config.

## Login identity options

Coppermine can configure login by:

- username;
- email address;
- either username or email.

This is separate from bridged identity behavior.

Mediarama should decide its own sign-in identifier policy, but migration must detect duplicate/missing email and username edge cases before assuming email-first authentication.

Coppermine can also allow duplicate email addresses, which is especially relevant if Mediarama later requires email uniqueness.

## User profile model

The core user table has six configurable profile fields:

- `user_profile1`
- `user_profile2`
- `user_profile3`
- `user_profile4`
- `user_profile5`
- `user_profile6`

Administrators can configure their display labels. Defaults include familiar profile concepts such as location/interests, but installations can repurpose the fields.

### Migration implication

The importer must not hard-code semantic names solely from default labels.

Preflight should capture:

- configured label for each profile field;
- whether the field is in actual use;
- data type/length realities;
- privacy implications.

Likely Mediarama mapping:

- known/recognized concepts → explicit profile field;
- installation-specific fields → structured legacy profile metadata or custom-field mechanism;
- empty unused fields → omit.

## Guest and anonymous behavior

Coppermine has a formal guest/anonymous group and a global `allow_unlogged_access` setting.

Guest behavior intersects with:

- gallery visibility;
- comments;
- uploads;
- ratings;
- e-cards/reporting;
- CAPTCHA;
- registration promotion.

Therefore "public gallery" and "anonymous capabilities" are separate concerns.

Mediarama should keep those concepts separate as well:

- public read access;
- guest interaction permissions;
- authenticated-user permissions.

## User self-service configuration

Confirmed gallery-level user settings include:

- member-list visibility to logged-in users;
- whether users may change their email;
- whether users may delete their own account;
- whether duplicate email addresses are allowed;
- whether users retain edit/delete control over files uploaded to public galleries.

These are product-policy decisions, not data that should be copied mechanically.

## Identity migration preflight requirements

Before importing users, preflight should report:

- whether the source is bridged;
- local vs externally authoritative identity assumptions;
- login method (username/email/both);
- duplicate-email count;
- accounts with empty/unusable email;
- active vs inactive users;
- configured custom profile field labels and usage;
- active bans;
- personal user galleries/albums;
- guest/public-access policy.

This preflight is required before the user importer can be considered production-safe.
