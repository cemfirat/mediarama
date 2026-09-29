# Coppermine official documentation cross-check

Status: **verified against current 1.6 manual pages**
Date: 2026-09-25
Tracking: #11

The source-code audit remains primary. This pass cross-checks several high-impact findings against the public Coppermine 1.6 documentation.

Official/current manual host checked:

- https://coppermine-gallery.com/docs/curr/en/

## Album keywords / linked media

Official keyword and album documentation confirms that album keywords are an intentional first-class feature, not an incidental SQL behavior.

The documented model is:

- a file can have multiple keywords;
- an album can have one album keyword/phrase;
- media physically stored in another album can appear as a linked file when its keywords match the album keyword;
- one physical file can therefore appear in multiple albums without duplicate source storage.

The manual also notes a practical limitation: an album containing only linked files cannot use those linked files as its album thumbnail in the same way as a native file.

Sources:

- https://coppermine-gallery.com/docs/curr/en/keywords.htm
- https://coppermine-gallery.com/docs/curr/en/albums.htm

### Mediarama consequence

The newly identified importer gap is confirmed by both implementation and official product documentation.

Migration must materialize effective album-keyword links into Mediarama's normalized `collection_media` relation.

## Album-level policy

The official albums manual confirms that permissions operate significantly at album level, including:

- upload permission;
- comments enable/disable;
- rating enable/disable;
- view visibility.

This independently supports Mediarama's direction toward resource-scoped collection policy instead of global role flags alone.

## Bridging risk

The official bridging documentation strongly reinforces the migration concern.

It explicitly explains that when bridging is enabled:

- the external application becomes the user-management authority;
- external user IDs are used;
- enabling bridging after existing Coppermine content can cause historical ownership to appear associated with a different external user sharing the same numeric ID.

The manual therefore warns about enabling bridging on an existing populated gallery and recommends backing up database/files first.

Source:

- https://coppermine-gallery.com/docs/curr/en/bridging.htm

### Mediarama consequence

Bridge detection is a migration preflight **blocker**, not optional metadata.

A bridged gallery cannot safely use a naive Coppermine-user-ID → Mediarama-user-ID migration strategy without understanding the active external identity mapping.

## Favorites ZIP download

The official configuration manual confirms:

- ZIP export operates on the user's favorites collection;
- it is configurable;
- an optional readme may be included;
- server resource use can become significant for large selections;
- the recommended default is off.

Source:

- https://coppermine-gallery.com/docs/curr/en/configuration.htm

### Mediarama consequence

Bulk download should be designed as a proper export job rather than a synchronous request over an arbitrary large selection.

This also validates the relationship between favorites and export workflows.

## Non-image media and custom thumbnails

The official files manual confirms Coppermine was expanded beyond still images to:

- video;
- audio;
- documents.

It also documents a layered custom-thumbnail lookup system:

1. user-defined;
2. theme-defined;
3. global;

and within those levels:

- file-specific thumbnail;
- extension-specific thumbnail;
- media-class thumbnail.

Custom thumbnails can use GIF/PNG/JPG.

Source:

- https://coppermine-gallery.com/docs/curr/en/files.htm

### Migration consequence

The importer cannot assume every visible Coppermine thumbnail is a derivative of the original media.

For non-image media, a thumbnail may be a separate user/theme/global asset.

Real migration tests must decide whether to:

- migrate a user-defined custom thumbnail as a Mediarama poster/cover derivative;
- regenerate a video poster;
- fall back to a media-type icon.

## Backup/restore clarification

The source-tree audit found no dedicated full-gallery backup/restore application entry point.

Official bridging documentation nevertheless explicitly recommends backing up:

- the Coppermine database;
- Coppermine files;

before risky integration changes and encourages regular database backups.

This supports the interpretation that backup is an important **operator responsibility**, but not necessarily a self-contained Coppermine backup subsystem.

### Mediarama consequence

Mediarama should document a complete backup boundary even if it does not initially implement an all-in-one backup UI.

## Research-method conclusion

This cross-check is useful because it distinguishes:

- accidental/legacy implementation details;
- intentional documented product semantics.

For architecture exit, high-impact findings should continue to be cross-checked this way when official documentation exists.
