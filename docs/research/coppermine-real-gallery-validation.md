# Real Coppermine 1.6 migration validation

Status: **required exit evidence**
Tracking: #8, #11

Synthetic fixtures are necessary for deterministic regression coverage, but they cannot replace one migration from a representative real or properly anonymized Coppermine 1.6 installation.

This validation is the final evidence gate before Coppermine can move from architecture/research input to compatibility-only maintenance.

## Required source material

A valid evidence run needs:

- a Coppermine 1.6 database restored into a disposable/read-only migration source;
- the matching Coppermine albums/media root;
- a documented source-table prefix;
- a stable unique `COPPERMINE_SOURCE_ID`;
- permission to use the source for migration validation.

The database and media root must belong to the same source installation/snapshot.

## Use a dedicated clean target

Evidence must run against a disposable target database/storage prepared with the current Mediarama migrations.

Do not run the evidence harness against a target that already contains users, media, collections, interactions, import state or queued jobs.

The harness fails if target content/import tables are non-empty. This keeps counts attributable to the tested source rather than to earlier work.

Typical preparation:

1. create a fresh PostgreSQL database;
2. point `DATABASE_URL` at it;
3. run `php bin/console doctrine:migrations:migrate --no-interaction`;
4. use a fresh empty `MEDIA_STORAGE_PATH`.

## Required environment

The validator expects:

- `DATABASE_URL`
- `MEDIA_STORAGE_PATH`
- `COPPERMINE_DATABASE_URL`
- `COPPERMINE_SOURCE_ID`
- `COPPERMINE_TABLE_PREFIX`
- `COPPERMINE_ALBUMS_ROOT`

`COPPERMINE_SOURCE_ID` identifies the source installation, not the database host. Keep it stable when retrying the same source and never reuse it for a different gallery.

Do not put credentials in the evidence report or commit them to the repository.

## Run

```bash
bin/validate-coppermine-real var/coppermine-real-evidence-example.md
```

The validator performs:

1. read-only schema inspection and fail-closed preflight;
2. clean-target verification;
3. the complete staged migration;
4. async media processing until the queue is empty;
5. source/target reconciliation;
6. technical evidence report generation.

It aborts on the first failed stage.

A report is only produced after the import and reconciliation are clean and no source media are left in failed processing state.

## What the report contains

The generated Markdown report records technical evidence only:

- UTC generation time;
- Mediarama commit;
- stable source key;
- detected source version;
- import run ID/status;
- source inspection counts;
- mapped users/groups/categories/albums/pictures;
- target media count;
- imported comment/favorite/rating counts;
- media processing-state counts for this source;
- reconciliation output.

It deliberately does **not** print:

- database credentials;
- source database URL;
- media-root filesystem path;
- user emails;
- comment bodies;
- image captions;
- exact GPS values.

If preflight/reconciliation fails, diagnostic command output may contain source identifiers needed to repair the migration. Do not attach a failed raw log publicly without reviewing it.

## Manual evidence review

Before attaching a successful report to #11, complete the report's manual review items:

- source ownership/permission confirmed;
- anonymization status recorded;
- installed/custom plugins reviewed;
- custom code/theme behavior reviewed for migration-relevant data;
- report checked for accidental personal/sensitive information;
- report approved for attachment.

A private customer/production source should normally be anonymized before any evidence file is committed or attached publicly.

## Representative-source expectation

The source should be more than an empty/default install.

Prefer an installation containing several of the following naturally occurring states:

- nested categories/albums;
- user galleries;
- public and restricted albums;
- comments;
- ratings/favorites where used;
- real EXIF/IPTC metadata;
- non-image media if the installation contains it;
- historic configuration/customizations.

The source does not need to exercise every synthetic negative blocker. Synthetic CI remains responsible for deterministic edge/error coverage.

## Extension/customization review

Preflight blocks:

- installed plugin registry rows;
- unknown tables using the configured Coppermine prefix;
- other known unsupported/high-risk source states.

That still cannot prove a historical plugin/customization never wrote data:

- under another database prefix;
- into separate tables without the Coppermine prefix;
- into files outside the normal albums root.

For a real installation, review its extension/customization history before declaring evidence complete.

## Exit rule

Do not check the real-gallery items in #8/#11 merely because the validator exists.

They can be checked only after:

- a real/anonymized 1.6 source was actually run;
- preflight passed or source-specific blockers were deliberately resolved;
- migration completed;
- async processing completed without failed source media;
- reconciliation was clean;
- the evidence report was reviewed and attached/recorded.

Until then Coppermine remains open as a migration/reference topic.
