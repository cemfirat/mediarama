# Public publication timeline

Status: foundation implementation  
Date: 2026-09-28

Mediarama keeps public publication time separate from record creation,
processing time, embedded capture time and ordinary database updates.

This distinction is required for truthful search/discovery metadata and
especially for future video-rich structured data.

## Fields

Both `media_assets` and `collections` persist:

- `public_published_at` — first known deliberate public/editorial publication;
- `public_updated_at` — latest known meaningful change to public page content;
- `public_published_origin` — `editorial` or `imported`;
- `public_published_source` — required only for imported publication dates.

Existing records are migrated with all four values **NULL**.

Mediarama does not backfill `created_at`, filesystem timestamps, capture dates
or the current migration time. Unknown is preferable to fabricated precision.

## First publication is immutable by normal publishing flow

`PublicPublicationTimelineStore::record*FirstPublication()` is idempotent.

The first call records the timestamp and provenance. Later calls — including a
normal unpublish/re-publish cycle — leave the first publication timestamp,
origin and source unchanged.

The first publication also initializes `public_updated_at` when no later
public-content timestamp is already known.

## Public content modification

`touch*PublicContent()` advances `public_updated_at` monotonically.

Callers must use this operation only for changes that materially affect the
public representation, for example:

- public title or description;
- public cover/presentation choice;
- deliberately public Collection membership that changes the page;
- future public player/presentation changes.

Do not touch it for:

- internal notes;
- raw EXIF/IPTC/XMP changes that are not publicly rendered;
- private ACL bookkeeping;
- processing telemetry;
- import checkpoints;
- background audit fields.

This is an explicit application contract rather than a database trigger so
internal writes cannot accidentally become public SEO timestamps.

## Reachability is separate

Publication timeline metadata does not grant public access.

A MediaAsset page still requires the existing effective public MediaAsset
boundary. A Collection page still requires effective public Collection
visibility. Site-level public publishing remains an independent gate.

Unpublishing or making a resource private does not erase historical editorial
publication metadata. Re-publishing does not invent a new first-publication
date.

## Imported timestamps

An imported publication date may be recorded only when the importer/operator
has a trustworthy source value.

Such a timestamp uses origin `imported` and requires an explicit source
identifier, for example `legacy-cms:source-key`.

Coppermine migration does not currently provide a trustworthy equivalent for
these semantics, so existing Coppermine imports remain unknown rather than
guessing from record or filesystem timestamps.

## Public read models

Public Collection and MediaAsset detail DTOs carry only the two public date
values:

- `publishedAt`;
- `publicUpdatedAt`.

Import provenance/source identifiers remain internal and are not exposed to
public templates.

Future VideoObject/video-sitemap and sitemap `lastmod` work may consume these
dates only when they are non-null. Rendering must never substitute `now` for
an unknown publication date.
