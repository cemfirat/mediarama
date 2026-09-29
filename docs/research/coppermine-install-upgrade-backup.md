# Coppermine installation, upgrade and backup/export audit

Status: **verified first-pass operational audit**
Date: 2026-09-25
Tracking: #11

Primary source: current Coppermine 1.6 installer/updater/version-check code and repository tree.

## Installation flow

The installer is a multi-step web installer.

Confirmed responsibilities include:

- determine Coppermine version;
- verify mandatory distribution files;
- check PHP version;
- require an XML parser capability for version/file checks;
- detect/test image processing backends;
- gather database connection/prefix settings;
- create database tables/config;
- create the initial administrator;
- write `include/config.inc.php`;
- test writable album/user directories;
- clean up temporary installer/test artifacts.

### Image-processing backend detection

The installer can detect/test:

- GD2;
- ImageMagick command-line;
- Imagick PHP extension.

This is useful operational knowledge, but Mediarama should avoid turning web-server writable application source/config into a runtime requirement.

## Filesystem expectations

The installer explicitly tests/chmods classic webroot storage paths such as:

- `albums/`;
- `albums/userpics/`;
- `albums/edit/`.

This reflects Coppermine's local-webroot storage architecture.

Mediarama's storage abstraction should instead validate:

- original-media storage;
- derivative storage;
- temp/chunk storage;
- application cache;
- queue/database connectivity.

The storage root does not need to be publicly web-readable.

## Database setup

Coppermine's installer supports MySQL-oriented database drivers and configurable table prefixes.

The initial administrator is inserted during installation and password hash parameters are generated.

Migration to Mediarama is independent from this installer behavior; source DB credentials should be read-only whenever possible.

## Upgrade flow

`update.php` performs substantially more than applying SQL.

Confirmed responsibilities include:

- normal application/admin authentication where possible;
- fallback DB/admin authentication;
- execute `sql/update.sql`;
- distinguish already-applied updates from errors;
- historical user-password conversion;
- historical album-password MD5 conversion;
- category-tree migration/repair;
- upload-plugin initialization/migration;
- delete obsolete files;
- rename/update system thumbnails/files;
- potentially rewrite configuration;
- point to version checking after update.

This is an important operational lesson:

> application upgrades can require coordinated database + filesystem + derived-data changes.

## Security observation from legacy updater

The source contains an emergency `SKIP_AUTHENTICATION` recovery mechanism with instructions for manually changing the updater source when credentials cannot be recovered.

That belongs to Coppermine's historical operational model and should **not** be reproduced in Mediarama.

Mediarama recovery should use explicit deployment/admin recovery mechanisms, not a source-code authentication bypass switch.

## Version/file integrity checking

Coppermine ships a file manifest such as `include/cpg16x.files.xml` and a `versioncheck.php` UI.

The system can reason about:

- mandatory files;
- version information;
- expected hashes;
- files marked for removal;
- permissions/readability;
- distribution/repository state.

### Mediarama equivalent

For modern packaged/containerized releases, the useful outcomes are:

- application version/build SHA;
- DB migration version;
- release compatibility;
- dependency/security status;
- storage/queue/database health;
- immutable release artifacts.

A literal per-PHP-file web UI is not required.

## Backup/restore finding

A repository-tree audit found no dedicated core `backup`, `restore`, `export`, or database-dump entry point in the current 1.6 application.

The repository does contain `include/archive.php`, but source references show it is used by `zipdownload.php` for user media ZIP creation, not as a full gallery backup system.

Therefore backup/restore should **not** be assumed to be a mature built-in Coppermine feature merely because an archive library exists.

This remains subject to official-documentation review, because Coppermine may instruct administrators to use external database/filesystem backup procedures.

## Mediarama product implication

Mediarama should treat backup/recovery as an explicit self-hosting concern even if Coppermine leaves it external.

A production-ready Mediarama should document or provide a coherent backup boundary covering:

- PostgreSQL;
- immutable originals;
- configuration/secrets;
- optional generated derivatives;
- extension/plugin-owned data;
- version information needed for restoration.

Because derivatives are regeneratable, a minimal backup policy may allow excluding them when the operator accepts regeneration cost.

## Upgrade requirements for Mediarama 1.0

Before a stable release, define:

- supported upgrade direction/version window;
- database migration execution strategy;
- preflight/health checks;
- backup recommendation/check;
- storage migration procedure when required;
- extension compatibility rules;
- worker/queue rollout ordering;
- rollback limitations;
- post-upgrade verification;
- application/DB schema compatibility reporting.

## Migration implication

A source Coppermine install can have historical upgrade residue:

- deprecated config values;
- old/extra files;
- partially converted password state;
- plugin upgrade data;
- custom table prefix;
- inconsistent category tree.

Mediarama's importer should inspect source capabilities/data rather than assume a pristine fresh-schema installation.
