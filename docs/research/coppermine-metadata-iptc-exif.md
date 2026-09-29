# Coppermine EXIF/IPTC metadata audit

Status: **active audit**
Date: 2026-09-25
Tracking: #9, #11

Primary source: current 1.6 metadata code, plus targeted 1.7 comparison.

## EXIF persistence model

Coppermine has a dedicated `exif` table:

- `pid`
- serialized `exifData`

When EXIF information is requested for a JPEG:

1. Coppermine checks for cached EXIF data in the DB;
2. if absent, it reads the file;
3. strips the raw structure down to a known field set;
4. serializes and stores that set for later use;
5. display fields are selected via `show_which_exif`.

The known field list includes camera/exposure fields such as:

- make/model
- DateTimeOriginal
- exposure time
- F-number
- focal length
- ISO
- flash
- metering mode
- exposure bias/program/mode
- white balance
- orientation
- color space
- image dimensions
- software
- sharpness/contrast/saturation and multiple maker-specific fields.

## Important migration consequence

Mediarama currently re-extracts metadata from the original file using ExifTool. That is the correct canonical path, but it is not sufficient to prove lossless migration.

The Coppermine EXIF table is a **cache of historical parsed data**. It can theoretically differ from the current file if:

- the physical file was changed after Coppermine cached EXIF;
- Coppermine's old parser captured a value ExifTool normalizes differently;
- the source file is now damaged/missing but cached metadata remains.

Therefore real migration audit should compare:

- embedded metadata extracted today;
- cached Coppermine EXIF data;
- normalized Mediarama fields.

Source-only cached values should be preserved in migration provenance/raw import metadata rather than silently lost.

## EXIF display configuration

`show_which_exif` stores field-selection state corresponding to the known EXIF field list.

The 1.6 and 1.7 base defaults differ in their pipe-delimited representation. The EXIF manager itself is shared/near-identical enough that this should be interpreted semantically before migration.

Mediarama should not copy this positional string. A future metadata-display configuration should use stable field identifiers.

## IPTC extraction

Coppermine can read IPTC from JPEG APP13 data.

Confirmed parsed IPTC fields include:

- Title
- Urgency
- Category
- SubCategories
- Keywords
- Instructions
- CreationDate
- CreationTime
- ProgramUsed
- Author
- Position
- City
- State
- Country
- TransmissionReference
- Headline
- Credit
- Source
- Copyright
- Caption
- CaptionWriter

## IPTC → gallery field import

During media ingestion, if IPTC reading is enabled, Coppermine imports into core picture fields **only when title, caption and keywords are all blank**.

It maps:

- IPTC Headline → title
- IPTC Caption → caption
- IPTC Keywords → Coppermine keyword string

If any of those three fields is already filled, the automatic IPTC replacement block does not run.

This precedence rule is migration-relevant because it helps explain whether Coppermine canonical title/caption/keywords came from embedded IPTC or user/upload input.

## 1.6 vs 1.7 metadata maintenance

Current 1.6 contains later maintenance changes not present in the dormant 1.7 copy.

One verified example:

- current 1.6 preserves IPTC SubCategories as a repeatable array;
- 1.7's older code reads the first SubCategory value only.

This is another example where 1.6 is the better current behavioral/security/maintenance reference despite the lower version line.

## Mediarama direction

Mediarama already improves the architecture substantially:

- ExifTool as primary metadata engine;
- raw/structured embedded snapshot;
- canonical normalized fields;
- provenance per canonical field;
- immutable original;
- editing canonical metadata separately from source;
- explicit export copies/profiles.

The Coppermine audit still needs to ensure migration captures source-side intent.

## Remaining metadata audit

Before this area is considered complete:

- decode representative Coppermine `exifData` rows safely;
- compare cached EXIF against ExifTool for real galleries;
- inventory all EXIF-manager selectable fields and labels;
- verify IPTC behavior for encoding/charset edge cases;
- inspect image-description/copyright precedence for representative real files;
- [x] inspect custom `user1..user4` field usage/migration;
- inspect whether plugins add metadata fields or override `file_info`;
- test metadata-heavy JPEGs;
- test source files missing while EXIF cache remains;
- build a migration provenance rule for Coppermine DB metadata vs embedded source.


## XMP behavior

A repository-wide source search finds no Coppermine XMP extraction/management subsystem comparable to EXIF or IPTC.

The bundled JPEG parser recognizes that APP1 may contain Adobe XMP, but its code path explicitly skips non-EXIF APP1 payload data rather than parsing XMP.

Therefore, in the checked 1.6/1.7 core:

- EXIF is parsed/cached/display-selectable;
- IPTC is parsed and can seed title/caption/keywords;
- XMP is not a first-class extracted/canonical metadata source.

This is a meaningful Mediarama improvement opportunity.

Mediarama's ExifTool-based inspection should retain XMP as first-class structured metadata and include it in source provenance rather than reproducing Coppermine's omission.

## Four custom media fields

Coppermine picture rows contain:

- `user1`
- `user2`
- `user3`
- `user4`

Their display labels are administrator-configurable through:

- `user_field1_name`
- `user_field2_name`
- `user_field3_name`
- `user_field4_name`

These fields are editable in the per-media editor and searchable.

### Migration rule

Do not assume fixed semantics.

Preflight should report configured labels and usage counts.

Possible Mediarama mapping:

- recognized, deliberately mapped labels → canonical/custom metadata field;
- installation-specific values → structured legacy/custom metadata preserving label + value;
- empty/unused fields → omit.

## Metadata feature classification

The core audit now distinguishes the complete built-in metadata families relevant to migration:

- title/caption/keywords;
- configurable custom media fields;
- EXIF cache/display selection;
- IPTC extraction and canonical seeding;
- XMP present only as skipped embedded data, not parsed by core;
- technical file/image dimensions/type;
- owner/upload timestamps and administrative state.

This is sufficient to avoid treating Coppermine metadata as "EXIF only".
