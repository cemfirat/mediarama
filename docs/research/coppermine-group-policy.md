# Coppermine group capability, quota and access-level audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #8, #11

Primary sources:

- current 1.6 `sql/schema.sql`
- `sql/basic.sql`
- `groupmgr.php`
- `bridge/udb_base.inc.php`
- `thumbnails.php`
- `displayimage.php`
- `include/themes.inc.php`

## User-group fields

Core `usergroups` fields include:

- `group_quota`
- `has_admin_access`
- `can_rate_pictures`
- `can_send_ecards`
- `can_post_comments`
- `can_upload_pictures`
- `can_create_albums`
- `pub_upl_need_approval`
- `priv_upl_need_approval`
- `access_level`

This is more than a simple role/capability table. Some fields are grants, some are moderation requirements, one is storage policy, and one controls the maximum media representation a member can access.

## Default groups

Current 1.6 seeds:

### Administrators

- admin access: yes
- rate/e-card/comment/upload/create album: yes
- public/private upload approval requirement: no
- access level: 3
- quota: 0

### Registered

- quota: 1024 KiB
- admin: no
- rate/e-card/comment/upload/create album: yes
- public uploads require approval: yes
- private uploads require approval: no
- access level: 3

### Anonymous

- quota: 0
- admin: no
- rate: yes in the raw default row
- e-card/comment/upload/create album: no
- public/private approval flags: yes
- access level: 3

Runtime guest behavior is also constrained by `allow_unlogged_access` and explicit guest data handling, so the raw Anonymous row is not by itself the full effective guest policy.

## Multiple-group merge semantics

Coppermine users can have a primary group and additional groups.

`get_user_data()` merges group rows using different aggregation rules depending on the field.

### Grant-style permissions

These use `MAX(...)`, so membership in **any** group granting the capability makes the effective capability true:

- `can_rate_pictures`
- `can_send_ecards`
- `can_post_comments`
- `can_upload_pictures`
- `can_create_albums`
- `has_admin_access`

### Access level

`access_level` also uses `MAX(...)`.

Therefore the user's most permissive member group wins.

### Approval requirements

These use `MIN(...)`:

- `pub_upl_need_approval`
- `priv_upl_need_approval`

Because `0` means "approval not required", membership in any group that does **not** require approval removes the approval requirement for the effective user.

This is an important migration semantic: moderation requirements are not independently accumulated per group.

### Quota

The query calculates:

- maximum group quota;
- minimum group quota.

Then effective quota becomes:

- `0` if any member group has quota `0`;
- otherwise the maximum quota among the user's groups.

In Coppermine, quota `0` therefore acts as an unlimited/no-limit value.

If every group has a finite quota, the most generous quota wins.

## Public-album creation is partly category-scoped

Effective `USER_CAN_CREATE_ALBUMS` combines:

- global `can_create_albums`;
- `can_create_public_albums`, which is derived from whether any of the user's groups appear in `categorymap`.

The code distinguishes:

- `USER_CAN_CREATE_PRIVATE_ALBUMS`
- `USER_CAN_CREATE_PUBLIC_ALBUMS`

This reinforces the earlier finding that `categorymap` is a real authorization relation, not decorative metadata.

## Access levels

Group manager exposes four levels:

- `0` — none;
- `1` — thumbnail only;
- `2` — thumbnail + intermediate;
- `3` — thumbnail + intermediate + full-size.

Runtime behavior confirms the distinction.

### Level 0

A logged-in user with level 0 is denied even thumbnail browsing.

### Level 1

Thumbnail pages remain available, but `displayimage.php` rejects access because only thumbnails are allowed.

### Level 2

The individual media page/intermediate representation is allowed, but theme/viewer paths block access to full-size media.

### Level 3

Full-size access is allowed subject to normal album/visibility policy.

### Anonymous users

For the anonymous group, the effective access level is controlled by `allow_unlogged_access`.

The setting has the same 0–3 representation concept.

## Mediarama implication: access to representations is a policy

The Coppermine model captures a useful product requirement that should not be lost:

> viewing a gallery does not necessarily imply permission to download/view the canonical full-size original.

Mediarama should model this more explicitly, for example through capabilities/policies such as:

- `media.view_preview`
- `media.view_large`
- `media.download_original`

The exact naming can remain a product design decision, but a simple `collection.view` rule is insufficient to represent all Coppermine installations.

## Mediarama implication: moderation policy

Public/private upload approval should become destination-aware moderation policy.

Possible model:

- group/role grants `media.upload`;
- destination collection determines whether upload is accepted into draft/pending state;
- user/group moderation exemption may bypass review where explicitly granted.

Do not encode "approval needed" as an ordinary permission row whose semantics are inverted.

## Mediarama implication: quota

The current Mediarama upload architecture has a quota abstraction but no complete persistent quota policy.

A migration-capable implementation should be able to represent:

- unlimited quota;
- finite per-user/group allowance;
- source-derived quota policy;
- current consumption;
- reservation during upload;
- final accounting after processing;
- release on failed/expired upload;
- owner/library accounting rules.

## Current importer gap

`CoppermineIdentityImporter::permissionKeys()` currently maps only:

- admin → `system.admin`
- upload → `media.upload`
- rating → `media.rate`
- comments → `media.comment`
- create albums → `collection.create`

It currently does **not** preserve:

- group quota;
- public/private approval policy;
- access level;
- e-card capability — likely intentionally obsolete;
- category-scoped public collection creation.

Therefore "groups imported" is not equivalent to "effective Coppermine group policy migrated".

## Migration preflight

Before group/identity migration, report:

- all group rows and member counts;
- users with secondary groups;
- effective merged quotas;
- unlimited quota cases;
- public/private approval exemptions;
- access-level distribution;
- categorymap-derived public collection creation rights;
- admin access granted through secondary group;
- bridged/custom group IDs;
- guest `allow_unlogged_access` value.

## Tests required

- single finite-quota group;
- multiple groups where one quota is unlimited;
- multiple finite groups with different quotas;
- one group removes upload-approval requirement;
- secondary group grants admin or upload capability;
- access levels 0/1/2/3;
- anonymous access levels 0/1/2/3;
- categorymap grants public collection creation while global private-album creation is absent;
- bridged group IDs/custom groups.

## Conclusion

Coppermine's group system encodes several mature policy dimensions that the current Mediarama importer only partially covers.

The target architecture should not copy the legacy table shape, but it must preserve the effective outcomes for quota, moderation, representation access and category-scoped creation before migration can be called complete.
