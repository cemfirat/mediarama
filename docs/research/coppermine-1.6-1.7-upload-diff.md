# Coppermine 1.6 vs 1.7 upload-path comparison

Status: **verified targeted comparison**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current `cpg1.6.x/develop`
- `cpg1.7.x/main`
- current 1.6 commit history/changelog for post-1.7 maintenance

This document compares actual upload code paths rather than assuming 1.7 is uniformly newer.

## Paths that are byte-identical

The checked current files are byte-identical between 1.6 and 1.7 for:

- `upload.php`
- `addpic.php`
- `searchnew.php`

This means the classic upload form and batch/server-side add entry point were not fundamentally redesigned in 1.7.

## Common processing remains shared in concept

Both branches continue to funnel successful placement into the same style of `add_picture()` processing in `include/picmgmt.inc.php`.

The mature behavior remains:

- extension/type policy;
- IPTC import;
- image inspection/orientation;
- optional upload resize;
- thumbnail/intermediate creation;
- watermarking;
- quota checks;
- approval;
- picture-row insertion.

1.7 does not replace this synchronous filesystem/DB processing model.

## 1.7 persists media type on the picture row

1.7 extends `pictures` with:

- `mime`
- `ftype`

and `add_picture()` populates them from inspected image/type information before the insert.

This is a useful incremental improvement but still keeps media identity/type in the legacy `pictures` record.

### Mediarama consequence

Mediarama should keep its stronger model:

- trusted type determined by content inspection/probing;
- explicit MediaAsset type;
- original storage identity independent of user filename;
- type discrepancy reporting during migration.

Source `mime/ftype` remain evidence, not authority.

## 1.7 lightweight bootstrap experiment

For non-simple HTML5 upload handling, 1.7 can require `include/cpg.inc.php` rather than the full `init.inc.php`.

`cpg.inc.php` extracts substantial bootstrap concerns:

- configuration/database;
- language;
- plugin loading;
- cookie consent;
- bridge/user authentication;
- basic authorization/debug state.

The full `init.inc.php` then adds the remaining full-page/theme/UI setup.

### Interpretation

This is a meaningful attempt to reduce coupling for request types that do not need the entire themed page stack.

It is **not** equivalent to Mediarama's target application/service boundary, but the intent is worth preserving:

> ingestion/API requests should not pay for or depend on presentation bootstrap.

Mediarama already achieves this more cleanly through controllers/application services/infrastructure.

## Chunk upload security divergence

Current 1.6 changed `upchunk.php` in the 2026-04-27 security commit:

- chunk `identifier` is wrapped in `basename(...)`;
- client filename is wrapped in `basename(...)`.

The commit message is:

`minor vulnerability mitigation; version 1.6.29`

The 1.7 branch still contains the older form without those `basename()` restrictions.

### Security lesson

Client-supplied chunk/session/file path material must never become a filesystem path component without strict server-generated identity or path confinement.

Mediarama's upload design should therefore enforce:

- server-generated upload/session IDs;
- numeric/validated chunk indexes;
- storage paths derived from server IDs, not client filenames;
- no traversal-capable path concatenation;
- final original filename retained only as metadata/display name;
- tests with traversal and separator payloads.

This is direct evidence that current 1.6 contains security knowledge absent from 1.7.

## 2026 current-1.6 upload UX/maintenance improvements

Current 1.6 continued evolving after 1.7 stopped.

Examples visible in code/history include:

- single-upload description length now uses configured `max_img_desc_length` instead of a hard-coded 512;
- HTML5 upload handling received later error-condition fixes;
- PHP 8.x compatibility/deprecation updates;
- dimension-restriction cleanup was fixed in 2023;
- HTML5 upload race/error behavior received maintenance fixes.

Therefore the 1.7 upload plugin copy is not the maintenance baseline.

## HTML5 upload notification divergence

The checked 1.7 HTML5 upload JavaScript sends upload-notification data as JSON and `notifyupload.php` reads from the JSON cage.

Current 1.6 uses `FormData`/POST for the same notification purpose and has later plugin revisions.

This is a transport-level difference, not a product capability difference.

Mediarama should expose a stable typed API and avoid coupling notification transport to the uploader implementation.

## Orphan cleanup difference in the checked code

In 1.7 `uniload.php`, if `add_picture()` returns failure after the file has already been renamed into the destination, the handler explicitly unlinks the destination file.

Current 1.6 does not have that exact generic unlink at the same location, but later 1.6 processing contains targeted cleanup fixes — for example the 2023 dimension-restriction path removes the placed upload itself.

### Mediarama consequence

Do not depend on scattered failure-path cleanup.

Use one ingestion transaction/saga model:

- temp/chunk object;
- validation;
- promote immutable original;
- persist finalization atomically;
- cleanup/retry as an idempotent operation;
- reconciliation job for orphan temp/permanent objects.

This remains an unresolved hardening area in Mediarama's current upload finalization.

## SWF uploader

The repository comparison confirms the legacy SWF uploader exists in current 1.6 but is absent from the 1.7 tree.

This is a clear legacy removal worth preserving as a **non-feature**:

- do not support Flash/SWF upload transport;
- migration can still encounter SWF as historical media content depending on source policy, but should never execute it.

## Type policy still extension-led in both branches

Despite 1.7 persisting MIME/type fields, the upload allowlist/classification path is still rooted in Coppermine's extension registry and configured allowed extensions.

Mediarama should not regress to that model.

Retain:

- extension only as advisory;
- MIME/magic/prober/decoder checks;
- explicit allowlist;
- decoder safety limits;
- separate policy for migration-only legacy formats.

## Approval/quota semantics remain legacy-compatible

The checked 1.7 processing keeps the same broad semantics for:

- personal-gallery group quota;
- public/private upload approval;
- admin bypass.

These capabilities remain important migration/product requirements even though Mediarama should implement them with modern policy services.

## Overall conclusion

1.7's upload work is evolutionary:

- media type persistence;
- some bootstrap separation;
- selected transport/UI updates;
- removal of SWF uploader.

Current 1.6 later accumulated additional security, cleanup, compatibility and UX knowledge.

For Mediarama:

> use 1.7 for selected design ideas, current 1.6 for later operational/security lessons, and neither branch as an implementation base.
