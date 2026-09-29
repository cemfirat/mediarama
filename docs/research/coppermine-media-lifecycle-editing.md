# Coppermine media lifecycle and editing audit

Status: **verified behavioral audit — replacement/upload edge cases remain open**
Date: 2026-09-25
Tracking: #11

Primary source: current 1.6 `include/picmgmt.inc.php`, `pic_editor.php`, `edit_one_pic.php`, `delete.php`, admin maintenance tools and related configuration.

## Ingestion-time image lifecycle

For image uploads Coppermine can:

1. inspect image dimensions/type;
2. apply EXIF orientation handling;
3. automatically resize an oversized upload;
4. optionally save a backup of the full-size image for watermarking;
5. create thumbnail;
6. create intermediate image when configured/needed;
7. watermark the resized and/or full-size image;
8. calculate total stored size;
9. enforce quota;
10. determine approval state;
11. create the database record.

This behavior has already informed Mediarama's asynchronous ingestion architecture.

## Derivative tiers

The classic image filesystem can contain:

- full/current image;
- intermediate image with `normal_` prefix;
- thumbnail with `thumb_` prefix;
- backup/original with `orig_` prefix when watermarking requires it;
- custom thumbnails for non-image files.

The exact prefixes are configurable.

### Mediarama improvement

Mediarama should keep explicit derivative entities/profiles rather than infer role from filename prefixes.

## Auto-resize of uploaded originals

If an uploaded image exceeds configured maximum dimensions, Coppermine may resize the **uploaded file itself** depending on administrator/user settings.

This is fundamentally different from Mediarama's immutable-original policy.

Mediarama should:

- reject media exceeding hard safety limits when necessary;
- otherwise keep the immutable original;
- generate constrained display/download derivatives;
- make destructive "replace original" an explicit separate product action, if ever supported.

## Watermark behavior

Coppermine can watermark:

- resized/intermediate image;
- full-size image;
- both;
- optionally custom thumbnails.

Before modifying a full-size image with watermarking, Coppermine can retain an `orig_` backup.

Maintenance tooling can later delete that backup, making the watermark effectively permanent.

### Mediarama decision

Do **not** reproduce destructive watermarking of the canonical original.

Watermarks should remain a derivative/export transformation:

- immutable source preserved;
- watermark configuration versioned;
- derivative regenerated when policy changes;
- metadata records whether a derivative was actually watermarked;
- export can apply a separate watermark policy.

This matches the current Mediarama direction.

## Built-in image editor

`pic_editor.php` provides direct editing capabilities including:

- crop;
- rotate where graphics backend supports it;
- dimension change/resize;
- JPEG quality choice;
- save edited full image;
- save custom thumbnail.

When saving an edited image, Coppermine can regenerate intermediate/thumbnail files and updates dimensions/file sizes in the database.

This is another destructive-source editing path.

### Mediarama direction

A future editor should use a non-destructive edit recipe where practical:

- crop rectangle;
- rotation/orientation;
- tonal/quality/export settings;
- focal point;
- custom thumbnail/crop.

The original stays immutable; derivatives are regenerated from original + recipe.

## Per-media metadata/management editor

`edit_one_pic.php` exposes a broad management surface.

Confirmed editable/operational data include:

- album assignment/move;
- title;
- filename;
- description;
- keywords;
- approval state;
- four configurable custom media fields;
- gallery icon/poster behavior for movie media;
- view-count reset;
- vote/rating reset;
- comment deletion;
- EXIF-cache refresh/re-read;
- physical filename rename across related derivative names.

Authorization considers:

- gallery admin;
- personal/user gallery ownership;
- source option allowing users to retain control of public-gallery uploads.

### Mediarama implication

Media editing should be decomposed rather than one giant form:

- descriptive metadata;
- collection membership;
- moderation;
- filename/download-name policy;
- technical metadata;
- derivatives/edit recipe;
- interaction/statistics reset;
- ownership/permissions.

## Moving media

Coppermine can move a media record to another permitted album by changing its `aid`.

Mediarama's n:m collection membership is more flexible.

The product should distinguish:

- add to another collection;
- remove from current collection;
- move as a convenience operation;
- change canonical ownership, which is not the same as collection movement.

## Filename rename

Coppermine supports changing a stored filename and attempts to rename associated full/intermediate/thumb files.

For non-image media it also accounts for shared/custom thumbnail naming.

Mediarama uses storage object identity independently from display/original filename, so user-visible renaming does not need to rename physical storage keys.

That is a deliberate architectural improvement.

## Approval/moderation after edit/move

When a non-admin moves/edits media, approval can be recalculated from group policy:

- public upload approval requirement;
- private upload approval requirement.

This confirms that moderation policy is tied to destination/resource context, not only to initial upload.

Mediarama should re-evaluate relevant authorization/moderation rules when changing collection/destination context.

## Quota

Coppermine checks group quota for personal/user gallery storage.

Quota is based on stored media/derivative size accounting.

The current Mediarama upload architecture has a quota seam but no persistent quota accounting yet.

This remains an important pre-release requirement.

## Deletion lifecycle

Deleting a picture can remove:

- full image;
- intermediate image;
- `orig_` backup;
- thumbnail;
- custom non-image thumbnail;
- comments;
- EXIF cache;
- picture row;
- album cover reference when it points to the deleted picture.

Plugins receive before/after delete hooks.

### Mediarama direction

Deletion needs an explicit retention model:

- soft delete/tombstone where appropriate;
- collection unlink vs asset delete must be separate;
- original deletion should be deliberate and auditable;
- derivative cleanup can be automatic;
- comments/ratings/favorites/report links need explicit cascade policy;
- storage deletion must be retryable and reconciled.

## User deletion behavior

Coppermine's user deletion workflow can:

- delete the user's personal albums;
- delete or anonymize their comments;
- delete or anonymize ownership of uploaded files;
- delete ban records;
- delete stored favorites;
- delete the user.

This reveals important account-erasure product semantics.

Mediarama needs a clear policy for:

- account deletion;
- ownership transfer/anonymization;
- comments;
- media;
- favorites;
- audit/security records;
- legal retention.

## Admin maintenance related to media lifecycle

Coppermine includes mature recovery/bulk operations such as:

- regenerate thumbnails/intermediates/full-size from backups;
- delete intermediates;
- delete watermark backups;
- refresh dimensions/filesizes from disk;
- reset views;
- reset ratings;
- delete orphan comments.

These are documented more fully in the maintenance audit.

## Migration implications

Real-world migration tests should include galleries where:

- full-size image was auto-resized;
- `orig_` watermark backup exists;
- intermediate is absent;
- custom thumbnail exists;
- file has been renamed;
- EXIF cache was manually refreshed/stale;
- media is pending approval;
- source user owns media in public album;
- linked album-keyword membership is present;
- non-image media uses custom thumbnail.

The importer must map the **current effective asset state**, not assume every Coppermine media directory has a canonical original + normal + thumb trio.

## Architectural conclusion

Coppermine's editing features are useful product knowledge, but they reinforce Mediarama's decision to diverge technically:

> Coppermine often treats filesystem files as mutable working state; Mediarama should treat the original as immutable and edits as explicit metadata/recipes/derivatives.


## Copy vs move vs replace

A targeted audit of:

- `editpics.php`
- `edit_one_pic.php`
- `picmgr.php`
- `displayimage.php`
- language/action labels
- the official current 1.6 Files and Albums manuals

finds a useful distinction.

### Move

Coppermine has a first-class **move-to-another-album** operation.

The editor changes the picture's `aid`.

Official documentation explicitly states that moving a picture between albums is a database operation: the physical file is not moved on the server.

For HTTP-uploaded files this means an album move does not imply a storage-path move.

### Show in multiple albums

Coppermine does **not** need to duplicate a file to show it in multiple albums.

The documented mechanism is album-keyword linking:

- one source picture row/file remains native to its original album;
- keyword matching makes it appear in additional albums.

This reinforces the Mediarama migration rule to materialize effective linked membership into `collection_media` rather than cloning MediaAssets.

### Copy

No first-class core "copy this media into another album as a second independent media/file" action was found in the audited editor/manager surfaces or current Files/Albums manual.

The supported user outcome "same file in multiple albums" is explicitly handled through keyword linking rather than physical/database duplication.

Therefore Mediarama does **not** need a Coppermine-compatibility copy primitive for migration parity.

Mediarama may still offer an explicit duplicate/copy feature later if useful, but that would be a Mediarama product feature, not a required Coppermine semantic.

### Replace binary with an arbitrary new upload

No first-class core editor action was found that uploads an arbitrary new source binary and swaps it into an existing picture row while preserving that picture identity.

The built-in image editor can destructively save transformations of the current image, and admin tools can regenerate corrupt derivatives.

Those are different from "replace source file with a new upload".

### Mediarama consequence

Keep these operations distinct:

- **move** → collection membership convenience;
- **link/add to collection** → n:m membership, no asset copy;
- **duplicate asset** → optional explicit Mediarama feature;
- **edit image** → non-destructive recipe/derivatives where possible;
- **replace original** → if ever supported, a deliberate version/replacement workflow with provenance, not an implicit file overwrite.

This closes the core add/edit/move/copy/delete/replace behavior inventory at the product-semantics level.
