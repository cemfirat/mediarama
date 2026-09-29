# Mediarama source provenance and Coppermine reuse audit

Status: **technical provenance review complete; project license confirmed as GPL-3.0-or-later**
Date: 2026-09-26
Tracking: #11

## Purpose

Mediarama is intentionally a clean implementation informed by Coppermine.

This audit asks a different question from the functional research:

> Is there evidence that Mediarama application source has copied/adapted Coppermine implementation code, creating provenance/licensing obligations beyond ordinary reference/migration compatibility?

This is a technical provenance audit, not legal advice.

## Existing architectural decision

ADR-0001 already states:

- Mediarama is a new application;
- Coppermine is a functional/behavioral/migration reference;
- code should not be copied merely for convenience;
- copied/adapted GPL code must be deliberately identified and reviewed.

That remains the correct policy.

## Repository search performed

Targeted repository searches were run for Coppermine-specific implementation markers.

### No application-source hits found for

- `IN_COPPERMINE`
- `Coppermine Dev Team`
- `cpg_db_query(`
- `add_picture(`
- `template_eval(`
- `Inspekt`
- Coppermine-style `unserialize(` implementation
- Coppermine-style `md5(` implementation

Search hits for `cpg_` are limited to:

- migration configuration/table-prefix examples;
- migration fixtures;
- research documentation.

This is positive evidence that the current Mediarama `src/` implementation was not created by mechanically copying obvious Coppermine PHP functions/classes.


## Full-history marker audit

The current-tree search is now backed by a repeatable Git-history control.

CI checks out the repository with `fetch-depth: 0` and runs `.github/scripts/audit-coppermine-provenance-history.sh`. The script scans the patch history of application and test code across every commit reachable from the PR head and fails if it finds implementation-specific Coppermine markers such as:

- `IN_COPPERMINE`;
- `cpg_db_query(`;
- `CPGPluginAPI::`;
- `template_eval(`;
- `cpg_die(`;
- `get_pic_url(`;
- `cpg_get_type(`;
- `cpg_get_alb_keyword`;
- `cpgSanitize`;
- Coppermine copyright/project signatures.

Research documentation, CI plumbing and the deliberately synthetic Coppermine SQL fixtures are excluded from this mechanical scan because those areas intentionally contain source names/schema facts.

A marker scan is not a mathematical proof that no code was ever semantically rewritten or renamed. It is a reproducible historical guard against the most likely accidental copy/paste paths and complements the architectural/manual review in this document.

### Targeted runtime similarity cross-check

The four highest-risk migration components were compared directly against the closest Coppermine runtime implementation files in both audited source lines.

Mediarama files:

- `CoppermineMediaImporter.php`;
- `CoppermineIdentityImporter.php`;
- `CoppermineCollectionImporter.php`;
- `CoppermineInteractionImporter.php`.

Compared upstream areas included `include/picmgmt.inc.php`, `include/functions.inc.php`, `bridge/udb_base.inc.php`, `catmgr.php` and `register.php`.

Against both pinned 1.6 and 1.7 source snapshots, normalized non-comment lines of at least 50 characters produced **zero exact shared lines** in those targeted pairs.

This is not legal proof of non-derivation by itself. Together with the full-history guard, architecture differences and repository review it supports the technical classification that the current importer is a clean Mediarama implementation of source-format behavior rather than a textual port of Coppermine runtime code.

## Coppermine names in application source

Mediarama application code does contain source-format names where required for migration compatibility, for example:

- `alb_password`
- source table/column names;
- `FIRST_USER_CAT = 10000`;
- Coppermine entity/table identifiers.

These are interoperability/schema facts used to read a Coppermine database.

They are not evidence by themselves of copied Coppermine runtime implementation.

## Synthetic migration fixtures

`tests/Fixtures/Coppermine/1.6-minimal.sql` and `1.7-variation.sql` are explicitly documented as hand-assembled interoperability fixtures based on verified upstream schema/configuration facts. They are not source database dumps and do not carry upstream comments or bundled gallery content.

The fixture directory now carries its own provenance README and each SQL file identifies the upstream model reference and license context.

Binary media is not kept as provenance-ambiguous repository content:

- JPEG fixtures are generated during CI with ImageMagick and receive synthetic EXIF/XMP/IPTC values via ExifTool;
- MP3 and MP4 fixtures are generated during CI with FFmpeg;
- no Coppermine sample photos, theme graphics, icons or fonts are bundled.

A future real/anonymized gallery fixture requires its own documented permission, source, anonymization process and retained-data scope before commit.

No production runtime code depends on a copied Coppermine SQL schema file.

## Research documentation

The research documents intentionally discuss Coppermine behavior, names, fields and code paths.

They contain paraphrased findings and short identifiers/snippets necessary to explain interoperability.

They should not become a repository for large copied source passages.

Current policy should remain:

- paraphrase behavior;
- name files/functions/fields as facts;
- quote only the minimum necessary;
- link/source the upstream project when appropriate.

## Current license state

The project licensing decision is to **retain the repository's existing `GPL-3.0-or-later` declaration**.

Both upstream reference lines checked for this audit carry GNU GPL v3 license text:

- Coppermine 1.6.x: `LICENSE.txt`;
- Coppermine 1.7.x: `LICENSE`.

The current technical audit found no copied/adapted Coppermine runtime implementation in Mediarama. GPL is therefore not being claimed as technically mandatory merely because behavior/schema facts were researched; it remains the deliberate Mediarama project license and is compatible with a future deliberate GPL-compatible reuse path.

If upstream implementation code is copied or adapted later, license compatibility alone is not enough: the exact source, upstream commit, copyright notices, destination and modifications must be recorded and preserved as applicable.

## LICENSE-file quality

The root `LICENSE` is replaced as part of the licensing close-out with the complete GPLv3 license text rather than the previous abbreviated pointer.

The project-level `GPL-3.0-or-later` choice remains declared in package metadata and the licensing policy document. Third-party dependencies retain their own licenses.

## Clean-room indicators in the current architecture

The following Mediarama structures are architecturally independent from Coppermine's runtime:

- Symfony/application-service architecture;
- PostgreSQL normalized target schema;
- UUIDv7 target identities;
- n:m Collection ↔ Media;
- Flysystem storage abstraction;
- immutable-original policy;
- asynchronous processing via Messenger;
- explicit derivative records;
- ExifTool metadata pipeline;
- typed domain/application classes;
- UIkit/Twig presentation boundary;
- explicit resource ACL model;
- staged MySQL/MariaDB → PostgreSQL importer.

These differences do not by themselves prove legal independence, but they are consistent with the documented clean-implementation strategy.

## Future provenance triggers

The current repository audit is complete. Provenance review must be reopened for a change when it introduces one of these new inputs:

- a real/anonymized gallery fixture;
- deliberately copied/adapted upstream implementation code;
- bundled third-party images, icons, fonts or media;
- ported third-party metadata/parser logic;
- vendored source not managed as a normal dependency.

The automated full-history marker audit remains a standing CI control.

## Rule for future Coppermine research

When a Coppermine behavior is useful:

1. document the observable requirement;
2. design the Mediarama model independently;
3. implement against that requirement;
4. write Mediarama-native tests;
5. do not paste upstream implementation unless deliberately choosing a derived GPL path and recording provenance.

## Recommended provenance record for deliberate reuse

If upstream code is ever deliberately adapted, create a record containing:

- upstream repository;
- exact path;
- upstream commit SHA;
- license;
- copied/adapted range;
- Mediarama destination;
- date;
- nature of modification;
- reason reuse was necessary.

That makes later license review auditable.

## Current conclusion

The technical provenance review finds **no identified copied/adapted Coppermine runtime implementation in Mediarama**.

Evidence includes:

- current-tree searches for Coppermine implementation markers;
- a CI-enforced full-history marker scan using an unshallow checkout;
- targeted exact-line similarity checks against high-risk 1.6 and 1.7 runtime areas;
- independent Mediarama architecture/data-model boundaries;
- explicit provenance for synthetic SQL/media fixtures;
- no bundled Coppermine photos, themes, icons or fonts;
- package-manager separation for third-party dependencies.

The technical classification is **clean implementation informed by Coppermine behavior/schema facts**.

Mediarama retains `GPL-3.0-or-later` as its project license. A future real/anonymized gallery fixture or deliberate upstream code reuse is a new provenance event and must carry its own record; it does not reopen already audited code unless that new material changes the evidence.

## Dependency and generated-asset boundary

Mediarama does not vendor Composer or npm dependency source into the repository: `vendor/`, `node_modules/` and generated `public/build/` are ignored.

UIkit, Symfony, Doctrine, Flysystem and build/test packages remain separately licensed dependencies resolved by their package managers. Their licenses are not reclassified as Mediarama-authored source merely because CI installs them or copies generated UIkit build artifacts into the ignored build directory.

The repository no longer stores binary MP3/MP4 fixture files; CI generates them with FFmpeg. JPEG fixtures are likewise generated with ImageMagick and populated with synthetic metadata via ExifTool.
