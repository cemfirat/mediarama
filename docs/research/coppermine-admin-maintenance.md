# Coppermine administration, maintenance and observability audit

Status: **active audit**
Date: 2026-09-25
Tracking: #11

Primary source: current Coppermine 1.6 code.

## Maintenance tool framework

Coppermine's `util.php` loads tools dynamically from `tools/<tool>/<tool>.php`.

Several tools can run progressively in batches, indicating mature operational handling for larger galleries.

Confirmed built-in operations include:

### Derivative/original maintenance

- regenerate thumbnails;
- regenerate intermediate/resized images;
- regenerate full-size from retained backup where possible;
- regenerate multiple derivative tiers together;
- delete intermediate images;
- delete original-size images by promoting the intermediate;
- delete watermark backup originals;
- delete files older than a specified age.

### Consistency and repair

- reload database file dimensions and size information from disk;
- identify/delete orphan comments whose media no longer exists.

### Bulk metadata/content operations

- filename → title conversion;
- bulk clear/change title, description and keywords;
- add/remove/clear keywords;
- convert the gallery-wide keyword separator.

### Statistics

- reset media/album view counters.

## Important architecture lesson

Coppermine's maintenance tools reveal mature **operator outcomes** that are easy to overlook when redesigning only the public gallery.

Mediarama should have explicit maintenance/repair commands for analogous outcomes, but they should use Mediarama invariants:

- immutable originals should not be deleted/promoted casually;
- derivatives are regeneratable artifacts;
- database/storage reconciliation should be first-class;
- metadata/index rebuilds should be idempotent;
- destructive maintenance should support dry-run and explicit confirmation;
- long operations should run as jobs/CLI rather than fragile web requests.

## Mediarama maintenance candidates

Before beta, plan a coherent maintenance surface for:

- regenerate derivatives by profile/version/media/collection;
- re-inspect metadata;
- rebuild search documents/indexes;
- reconcile DB originals vs storage objects;
- reconcile derivative DB rows vs storage;
- verify checksums;
- detect orphan database records;
- detect orphan storage objects;
- recalculate quotas/storage totals;
- retry failed processing;
- clear/rebuild derived statistics;
- migration reconciliation.

## Logging and debug output

Coppermine has:

- configurable application logging through `include/logger.inc.php`;
- global/admin logging modes;
- `viewlog.php`;
- debug output with request, query and performance data;
- PHP/server module diagnostics;
- peak page generation/query measurements;
- plugin listing in debug output;
- key configuration display.

### Security lesson

The old debug surface can expose sensitive request/cookie/session/config information.

Mediarama should preserve observability while avoiding accidental sensitive-data disclosure.

Recommended Mediarama direction:

- structured application logs;
- request/correlation IDs;
- job/import IDs;
- security-event logs;
- processing failure diagnostics;
- admin-safe health checks;
- redaction by default;
- environment-gated developer diagnostics;
- metrics rather than dumping cookies/sessions/queries into public HTML.

## Installation and database updates

Coppermine's `update.php` performs both database and filesystem upgrades.

Observed responsibilities include:

- authenticate administrator or DB account;
- execute `sql/update.sql`;
- tolerate already-applied statements;
- perform historical password hashing conversions;
- perform album-password hashing conversion;
- update category tree data;
- migrate upload plugin/mechanism state;
- delete/rename files;
- update system thumbnails;
- offer a version check after upgrade.

### Mediarama implication

A serious 1.0 requires an upgrade story, not merely fresh installation.

Mediarama should provide:

- versioned DB migrations;
- explicit application compatibility checks;
- preflight before irreversible migrations;
- backup recommendation/checkpoint;
- no web-editing of shipped source files as part of ordinary upgrades;
- migration status/rollback policy where feasible;
- release notes for destructive changes.

## Version checking

`versioncheck.php` compares the installation against a repository/file manifest and can report file/version status.

This reflects another useful operational outcome: detecting stale/missing/modified installation files.

For packaged/containerized Mediarama deployments, the equivalent may be:

- application version/build SHA;
- DB migration version;
- dependency/security status;
- health/readiness;
- immutable release artifact verification.

Exact file-by-file PHP comparison is not necessarily a Mediarama requirement.

## Keyword and EXIF managers

Coppermine exposes dedicated admin surfaces for:

- keyword management;
- EXIF field/display management.

Mediarama should retain the broader idea that metadata administration is not only per-media editing.

Potential Mediarama admin functions:

- tag/keyword rename/merge;
- metadata field visibility configuration;
- bulk metadata operations;
- metadata provenance inspection;
- export-profile management.
