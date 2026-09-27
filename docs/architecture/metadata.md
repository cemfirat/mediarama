# Metadata Architecture

Status: **foundation design**
Date: 2026-09-25

Mediarama treats embedded metadata as a first-class capability.

## Core principle

Metadata is extracted once during ingestion/import and persisted for fast query/search.

The application does **not** re-read the source file for normal gallery rendering, filtering or search.

The original embedded metadata is preserved as a source snapshot, while selected fields are normalized into query-friendly columns/tables.

## Metadata layers

### Embedded source snapshot

Captured during ingestion:

- EXIF
- IPTC
- XMP
- ICC/profile summary where useful
- decoder/probe technical metadata

Stored in JSONB under stable top-level namespaces:

```json
{
  "exif": {},
  "iptc": {},
  "xmp": {},
  "icc": {},
  "technical": {}
}
```

Raw binary metadata blobs are not stored in PostgreSQL unless a specific format requires it.

### Canonical metadata

Editable Mediarama values used by UI/search/export:

- title
- description
- captured_at
- creator
- copyright
- location_name
- latitude / longitude
- camera_make
- camera_model
- lens
- iso
- aperture
- exposure_time
- focal_length
- tags

Canonical values may initially be seeded from embedded metadata but become independent once edited.

### Provenance

Every canonical field that can be populated automatically should preserve its provenance.

Initial provenance values:

- embedded
- coppermine_import
- migration
- user
- automated

Field provenance belongs in a dedicated JSONB map:

```json
{
  "title": "embedded",
  "captured_at": "embedded",
  "copyright": "user"
}
```

This allows export logic to explain which values are original and which were edited later.

## Search model

Frequently searched fields are normalized/indexed.

Do not force normal searches through arbitrary JSONB traversal.

Initial normalized fields on `media_assets`:

- captured_at
- creator
- copyright
- camera_make
- camera_model
- lens
- iso
- aperture
- exposure_time
- focal_length
- latitude
- longitude
- location_name

Tags remain relational.

The full embedded metadata snapshot remains available for detailed inspection and future fields.

## Editing

Editing metadata:

1. updates canonical database fields;
2. updates provenance for changed fields to `user`;
3. updates search indexes;
4. does not mutate the immutable original;
5. records audit information when audit infrastructure is available.

## Export profiles

Exports are generated artifacts.

Initial policies:

- `original` — untouched original bytes;
- `current` — write current canonical metadata into a generated copy;
- `privacy_safe` — current metadata with sensitive fields omitted;
- `custom` — field/group selection.

Sensitive groups include at least:

- GPS/location;
- camera/device serials;
- owner/contact details;
- internal/private Mediarama fields.

## Format strategy

Preferred write tool should support broad EXIF/IPTC/XMP compatibility and preserve unknown metadata where possible.

The infrastructure adapter must expose capabilities per format rather than pretending every format has identical write support.

RAW source files are not modified destructively. Current, privacy-safe and custom metadata can be exported through a dedicated XMP sidecar writer. The sidecar path is generated independently of the immutable original; sidecar export does not read or rewrite RAW bytes.

The RAW sidecar boundary is selected by the original filename extension rather than trusting a single MIME spelling, because RAW MIME detection varies across platforms and camera formats. The initial supported RAW extension set includes DNG, CR2/CR3, NEF/NRW, ARW, RAF, ORF, RW2 and PEF plus other common camera RAW extensions handled by the writer.

## Batch exports

Large multi-file/ZIP exports are asynchronous jobs.

The export job receives:

- media IDs;
- export profile;
- output format/options;
- requesting user;
- authorization snapshot/reference.

Each generated file passes through metadata-policy application before packaging.

## Invalidation

If canonical metadata changes:

- normal gallery/search sees the new database value immediately;
- existing cached export artifacts based on old metadata must be invalidated/versioned;
- original and display derivatives need not be regenerated unless the metadata is visibly rendered into them.

## Metadata processing flow

```text
Original stored
   ↓
Media inspection
   ├── MIME/type
   ├── dimensions/duration
   ├── EXIF
   ├── IPTC
   ├── XMP
   └── technical metadata
   ↓
Raw snapshot JSONB
   ↓
Canonical field mapper
   ↓
Normalized searchable fields + provenance
   ↓
MediaAsset ready for derivative processing/search
```
