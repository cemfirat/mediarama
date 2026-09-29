# Coppermine core table relationship map

Status: **verified logical relationship audit**
Date: 2026-09-25
Tracking: #8, #11

Primary sources:

- current 1.6 `sql/schema.sql`
- runtime queries in gallery, identity and migration-relevant code
- 1.7 schema comparison

## Important structural fact

Coppermine's schema does **not** express these relationships with database foreign-key constraints.

They are application-level relationships enforced by PHP and conventions.

This matters for migration because real installations can contain orphaned or inconsistent references.

A Mediarama importer must validate/reconcile instead of assuming referential integrity.

## 22 core tables and logical relationships

### `albums`

Primary identity: `aid`.

Logical relationships:

- `category` → ordinary `categories.cid`, **or** a virtual user-gallery ID `FIRST_USER_CAT + user_id`, **or** zero/no-category context;
- `owner` → user identity;
- `thumb` → `pictures.pid` when an explicit album thumbnail is selected;
- `visibility` → public zero, group ID, or virtual user/private principal depending on value;
- `moderator_group` → historically a user group, but current 1.6 treats this as disabled/legacy state;
- `keyword` creates dynamic membership links to picture keyword strings.

This table therefore contains several polymorphic/convention-based references.

### `banned`

- optional `user_id` → user;
- name/email/IP can also identify a ban without a strict user relation;
- brute-force rows are temporary security state.

### `bridge`

Key/value configuration for external identity integration.

No relational FK structure.

Semantics can redefine what the `users` table means operationally.

### `categories`

- `parent` → another `categories.cid` or root zero;
- `owner_id` → user where used;
- `thumb` → picture ID for chosen category thumbnail;
- nested-set fields `lft/rgt/depth` duplicate hierarchy information for traversal.

The hierarchy therefore has both parent adjacency and nested-set state that can become inconsistent.

### `categorymap`

Composite logical relation:

- `cid` → category;
- `group_id` → user group.

Meaning: group may create/manage public albums under that category.

This is authorization data, not content membership.

### `comments`

- `pid` → picture;
- `author_id` → user when registered, otherwise zero;
- `author_md5_id` is browser/guest identity state rather than a relational user.

### `config`

Name/value configuration.

Values can contain references to themes, upload plugins, paths and serialized/positional settings, but these are not database FKs.

### `dict`

Standalone keyword dictionary.

`keyword` corresponds semantically to tokens used in picture keywords, but there is no relational join table.

### `ecards`

Standalone communication log.

Its encoded `link` can carry media/message context, but there is no normalized relational media/recipient model.

### `exif`

- primary `pid` logically → picture;
- `exifData` is serialized cached metadata.

This is a one-to-zero/one cache relation.

### `favpics`

- `user_id` → user;
- `user_favpics` contains a serialized/base64 picture-ID list.

Picture membership is therefore embedded in a text blob, not normalized.

### `filetypes`

Standalone extension registry:

- extension;
- MIME;
- content class;
- player.

Pictures reference it only indirectly through their filename/type lookup.

### `hit_stats`

- `pid` is a string-form picture/media identifier in ordinary usage;
- `uid` → user when available.

No DB FK and privacy-sensitive telemetry surrounds the relation.

### `languages`

Standalone language registry/configuration state.

No direct user FK, although users also store a language string.

### `pictures`

Primary identity: `pid`.

Logical relationships:

- `aid` → album;
- `owner_id` → user;
- `galleryicon` is a per-user-gallery marker used when selecting representative media;
- keywords can dynamically link the picture into additional albums by album keyword;
- in 1.7 `mime/ftype` add persisted type evidence but do not create a new relational media model.

This is the central entity from which comments, EXIF, votes, stats, favorites and covers depend.

### `plugins`

Standalone plugin registry:

- path/name/enabled/priority.

Plugin-owned tables/configuration are outside this core relationship map and must be inventoried separately.

### `sessions`

- `user_id` → user;
- `session_id` is authentication state.

Sessions are intentionally not migrated.

### `temp_messages`

- `user_id` may associate a temporary cross-page message with a user;
- otherwise ephemeral application state.

Not migration content.

### `usergroups`

Primary group identity and global group policy.

Referenced by:

- users' primary group;
- users' additional group list;
- categorymap;
- album visibility values;
- legacy moderator group.

### `users`

- `user_group` → primary user group;
- `user_group_list` stores additional group IDs in a delimited string rather than normalized membership rows.

When bridging is enabled, the local users table may no longer be authoritative.

### `votes`

- `pic_id` → picture;
- `user_md5_id` is an anti-repeat identity token, not a normalized user FK.

Numeric rating value is absent.

### `vote_stats`

- `pid` → picture in ordinary use;
- `uid` → user when logged in;
- detailed rating value and client telemetry are retained when detailed stats are enabled.

## High-risk non-relational encodings

The audit identifies several places where logical relations are embedded in values rather than normalized.

### Virtual user category IDs

`FIRST_USER_CAT + user_id`.

Used in:

- album category;
- album visibility/private access.

### Additional user groups

`users.user_group_list` contains multiple group IDs in text form.

### Favorites

Serialized/base64 picture IDs.

### Album keyword links

Picture ↔ album membership through keyword string matching.

### Album-password unlocks

Serialized browser cookie mapping album IDs to legacy password hashes.

### EXIF

Serialized metadata cache.

### E-card/report payloads

Encoded message/media context.

### Configuration

Several positional, delimiter-based or path/plugin identifiers.

These are exactly the structures most likely to be missed by a naive relational migration.

## Cover/thumbnail references

Coppermine has multiple "representative image" concepts:

- `albums.thumb` → explicit picture used as album cover;
- `categories.thumb` → explicit category thumbnail picture;
- `pictures.galleryicon` → user-gallery representative marker;
- fallback logic can derive thumbnails when explicit IDs are absent.

Mediarama should treat cover/poster selection as explicit collection/user presentation data rather than infer it from file naming.

Migration should validate referenced media and fall back safely if missing.

## Cascade behavior is application-managed

Because there are no FKs/cascades, delete code manually cleans selected related data.

Example picture deletion explicitly removes:

- files/derivatives;
- comments;
- EXIF;
- picture row;
- album cover references.

Other dependent data can have different cleanup paths.

This is why reconciliation is necessary for real source galleries.

## 1.6 vs 1.7 relationship conclusion

The checked 1.7 schema preserves the same 22-table relationship model.

The main structural media change is additional `pictures.mime` / `pictures.ftype` fields.

No normalized replacement appears for:

- user group membership;
- favorites;
- tags/keywords;
- album linked membership;
- ACL;
- sessions;
- plugin ownership.

Therefore the relationship architecture remains fundamentally the same.

## Mediarama migration consequences

Preflight/reconciliation must detect:

- pictures whose album is missing;
- albums whose normal category is missing;
- category parent cycles/orphans;
- inconsistent nested-set hierarchy;
- missing album/category cover pictures;
- comments/EXIF/votes/stats pointing at missing pictures;
- favorites containing missing picture IDs;
- users/groups referenced but missing;
- categorymap orphan rows;
- virtual user-gallery IDs whose user does not exist;
- album visibility principals that cannot be mapped;
- additional-group IDs that do not exist;
- plugin-owned tables outside the core schema.

## Target-model conclusion

Mediarama's normalized model is justified by these findings:

- explicit `collection_media`;
- normalized user/group membership;
- explicit `collection_access`;
- normalized favorites;
- normalized tags;
- explicit derivative entities;
- explicit metadata/provenance;
- explicit import mappings/checkpoints.

The importer must still understand Coppermine's implicit relationships before normalization can be considered loss-aware.
