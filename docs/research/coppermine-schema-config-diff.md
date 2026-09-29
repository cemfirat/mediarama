# Coppermine 1.6 vs 1.7 — schema and configuration diff

Status: **verified structural audit**
Date: 2026-09-25
Tracking: #11

Primary sources:

- 1.6 `sql/schema.sql`, `sql/basic.sql`, `sql/update.sql`
- 1.7 `sql/schema.sql`, `sql/basic.sql`, `sql/update.sql`

## Table inventory

Both schema files define the same 22 core table names:

1. albums
2. banned
3. bridge
4. categories
5. categorymap
6. comments
7. config
8. dict
9. ecards
10. exif
11. favpics
12. filetypes
13. hit_stats
14. languages
15. pictures
16. plugins
17. sessions
18. temp_messages
19. usergroups
20. users
21. votes
22. vote_stats

This is important: 1.7 does **not** introduce a new normalized data model.

## Field-level schema difference

A full normalized table-definition comparison finds three structural differences in the current checked schemas.

### `pictures`

1.7 adds:

- `mime varchar(255) NOT NULL default 'image/*'`
- `ftype varchar(32) NOT NULL default 'image'`

### `hit_stats.ip`

- current 1.6: `varchar(40)`
- 1.7: `varchar(20)`

### `vote_stats.ip`

- current 1.6: `varchar(40)`
- 1.7: `varchar(20)`

The 1.6 update script explicitly describes the move to 40 characters as a fix to accommodate IPv6 addresses. This is a concrete example where current 1.6 contains a post-1.7 maintenance improvement that the dormant 1.7 schema did not receive.

No other normalized column/index/key line differs in the checked schema definitions.

This is strong evidence that 1.7's media-type work is an incremental extension of the 1.6 picture table, not a redesigned media-asset model.

## 1.7 update script

The 1.7 `sql/update.sql` explicitly performs:

- `ALTER TABLE CPG_pictures ADD mime ... default 'image/*'`
- `ALTER TABLE CPG_pictures ADD ftype ... default 'image'`
- adds config `thumbs_per = 20`

This aligns with the schema diff above.

## Base configuration comparison

Parsed `sql/basic.sql` contains:

- 221 config defaults in current 1.6;
- 217 config defaults in 1.7.

### Added in 1.7

- `thumbs_per`

### Present in 1.6 but absent from 1.7 base config

- `comment_email_notification`
- `display_admin_uploader`
- `display_sidebar_guest`
- `display_sidebar_user`
- `randpos_interval`

These removals must be interpreted through code/commit history before they are classified as deliberate feature removals. They are not yet automatically "features removed from 1.7".

### Changed default values

`cookie_name`:

- 1.6: `cpg16x`
- 1.7: `cpg17x`

`show_which_exif` differs in its serialized/pipe-delimited default representation. This requires field-index interpretation against the EXIF manager before assigning semantic meaning.

## Architectural implication

The structural comparison reinforces the working hypothesis:

- 1.7 is schema-compatible in spirit with 1.6;
- media handling is extended through fields on `pictures`;
- existing tables for favorites, e-cards, EXIF, hit stats, plugins, bridges, sessions and votes remain;
- therefore those mature concepts still deserve explicit review even if Mediarama later omits some of them.

## Migration implication

The importer should remain capability-based instead of trusting a version string.

In particular:

- presence of `pictures.mime` / `pictures.ftype` is a useful 1.7 capability indicator;
- identical table names do not imply identical semantics/configuration;
- config and upgrade history must be inspected when migrating behaviors that are not represented by schema alone.

## Remaining schema/config audit

Still required:

- [x] compare current schema indexes, key definitions, defaults and column types at normalized definition-line level;
- inspect every `sql/update.sql` statement historically relevant to current 1.6;
- map config keys to actual code paths and feature flags;
- identify deprecated-but-still-readable keys;
- compare plugin/bridge-related persisted configuration;
- map EXIF configuration positions to actual metadata fields;
- map hit/statistics retention settings;
- map session/cookie/privacy settings;
- map upload/file-type configuration into migration validation.
