# Coppermine album visibility, passwords and moderator-state audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #8, #11

Primary sources:

- current `cpg1.6.x/develop`
- `sql/schema.sql`
- `include/functions.inc.php`
- `thumbnails.php`
- `modifyalb.php`
- `db_input.php`
- `include/init.inc.php`
- `sql/update.sql`

## Visibility encoding

Coppermine stores album visibility in a single integer field.

Confirmed semantics from `form_visibility()` and the private-album filter are:

- `0` — public;
- ordinary group ID — members of that group;
- `FIRST_USER_CAT + user_id` — a specific user / "me only" style access;
- in a personal-gallery context the virtual user-gallery category ID can represent owner-only access.

This is not a modern ACL table. One integer encodes several principal types depending on its numeric range.

### Mediarama mapping

Mediarama should continue transforming this into explicit resource policy:

- public → Collection visibility public;
- group visibility → `collection.view` rule for mapped group;
- user/private visibility → `collection.view` rule for mapped user;
- unresolved principal → fail closed and report.

The current `CoppermineAclImporter` already implements the basic user/group mapping.

## Global private-album switch

`allow_private_albums` can disable private-album behavior globally.

When disabled, the album-edit form forces visibility to `0`.

The official UI text also warns that switching this setting off makes existing private albums visible.

### Migration rule

Mediarama migration must classify both:

1. the persisted album visibility value;
2. the effective source behavior under `allow_private_albums`.

A migration should not accidentally publish historically private albums merely because the source installation currently has a global switch in an unusual state.

The safer migration default remains fail-closed: any non-zero source visibility becomes restricted until its principal is mapped.

## Password-protected album access

Coppermine album rows contain:

- `alb_password` — legacy MD5-style hash storage;
- `alb_password_hint`.

The password is integrated with the private/restricted-album mechanism.

### Effective behavior

`get_private_album_set()` first computes albums the current user is not otherwise allowed to view.

`cpg_pw_protected_album_access()` then distinguishes:

- already allowed by ordinary visibility rules;
- forbidden but password-protected;
- forbidden and not password-accessible.

In `thumbnails.php`:

1. if the album is already outside the forbidden set, it is considered valid without asking for the album password;
2. if it is forbidden and a password is supplied, Coppermine hashes the submitted password with MD5 and compares it to `alb_password`;
3. after a successful check, the album ID and password hash are remembered in a serialized browser cookie;
4. later requests can use that cookie value to exempt the album from the forbidden set.

This is an important semantic detail:

> the album password acts as an alternative way into an otherwise restricted album; it is not, in the ordinary path, an independent second factor that every already-authorized viewer must also enter.

## Legacy album-password cookie

The cookie named from `<cookie_name>_albpw` contains a serialized album-ID → password-hash map.

The server deserializes it and validates stored hashes against current album rows.

### Mediarama security rule

Never import or trust this cookie.

Do not migrate:

- old album-password cookie state;
- old MD5 album-password hashes as usable Mediarama credentials;
- historical unlock state.

If browser-assisted migration is ever considered, old album-password state still must not become Mediarama authorization state.

## Current Mediarama importer behavior

The existing `CoppermineAclImporter` deliberately:

- marks a password-bearing collection restricted;
- records that password protection existed;
- keeps the source password hint;
- leaves the target password hash null;
- marks password reset/replacement as required.

This is the correct migration direction.

A source album that depended on a password therefore stays fail-closed until the administrator deliberately establishes a new Mediarama password/policy.

## Password hints

Password hints may contain installation-specific text that remains useful after migration.

They can be preserved as descriptive migration data, but operators should be warned that a hint might disclose sensitive information.

A future admin migration review should allow:

- keep;
- edit;
- discard.

## `show_private`

The `show_private` configuration affects whether private-album placeholders/icons are shown to unauthorized visitors.

It is presentation/discovery behavior, not an access grant.

Mediarama should not map it into ACL.

If Mediarama later exposes restricted collection placeholders, that should be a separate product/privacy setting.

## Album moderator group: legacy/disabled feature

The schema still contains:

- `albums.moderator_group`.

The runtime also contains residual support:

- `include/init.inc.php` can derive `USER_DATA['allowed_albums']` from `moderator_group`;
- `editpics.php` can enter moderator mode for such albums;
- the album-edit form still contains moderator UI code.

However, current 1.6 also contains strong evidence that this feature is not actively supported as normal configuration:

- the `db_input.php` code that would persist `moderator_group` changes is commented out with a TODO to re-enable/test the feature;
- `sql/update.sql` explicitly resets all `moderator_group` values to `0` and states that the reset should be removed only if the feature is re-enabled.

### Migration rule

Do **not** blindly import `moderator_group` as an active Mediarama authorization rule.

Instead preflight should:

- count non-zero `moderator_group` values;
- flag them as legacy/custom-install evidence;
- require explicit review if any exist;
- inspect installed plugins/custom code before deciding whether those values still had operational meaning.

This avoids granting unintended moderator rights from stale schema residue.

## Resource-policy model derived from Coppermine

The full Coppermine audit now shows that collection-level policy needs to distinguish more than view access:

- `collection.view`
- `collection.create_child`
- `collection.edit`
- `collection.delete`
- `collection.media.add`
- `collection.media.manage`
- comment permission/policy
- rating permission/policy
- ownership policy

Password access should be modeled as another controlled access mechanism, not as a special case hidden inside visibility integers.

## Migration preflight checks required

Before importing collections/ACLs, report:

- count of public albums;
- group-restricted albums;
- user/private albums;
- password-bearing albums;
- password hints present;
- unmappable visibility principals;
- `allow_private_albums` source value;
- non-zero `moderator_group` rows;
- albums with contradictory/custom states;
- source plugins that may alter album authorization.

## Tests required

At minimum:

- public album;
- group-only album;
- user-only album;
- personal-gallery owner-only album;
- password-restricted album;
- password album whose ordinary visibility already grants access;
- source with `allow_private_albums = 0`;
- missing mapped user/group;
- non-zero legacy moderator group;
- serialized album-password cookie present — verify it is ignored by migration.

## Conclusion

Coppermine album access is more nuanced than a single "private" flag.

Mediarama's explicit ACL direction is correct, but migration completeness requires preserving **effective source visibility semantics** while deliberately refusing to carry forward weak legacy password/session state.
