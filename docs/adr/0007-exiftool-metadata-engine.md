# ADR-0007: Use ExifTool as the metadata engine

- Status: Accepted
- Date: 2026-09-25

## Context

Mediarama must preserve one of Coppermine's strongest capabilities: rich image metadata extraction.

Requirements now go further than Coppermine:

- full EXIF/IPTC/XMP extraction;
- searchable normalized metadata;
- preservation of the original embedded snapshot;
- metadata editing in Mediarama;
- selectable metadata policies during download/export;
- broad format support;
- RAW-safe behavior;
- future batch processing.

## Decision

Mediarama will use **ExifTool** as the primary metadata inspection and writing engine.

ExifTool is invoked through a Mediarama infrastructure adapter. Domain and application code depend only on Mediarama interfaces such as `MediaMetadataInspector` and `MetadataWriter`.

ExifTool is not called directly from controllers or templates.

## Extraction mode

The adapter should request structured JSON output and grouped tag names.

Preferred shape:

```text
-json
-struct
-G1:4
-a
-n
```

Family 1 preserves the specific metadata location/namespace (for example `IFD0`, `ExifIFD` and `XMP-dc`). Family 4 adds an instance group where necessary so duplicate tags receive unique JSON names instead of being silently suppressed.

The stored snapshot keeps the complete group-qualified tag key rather than stripping the group name.

Important: do not flatten away XMP structures or group identity unnecessarily.

## Performance

ExifTool process startup has measurable overhead.

Foundation implementation may invoke one process per job/file for simplicity and isolation.

When bulk import/processing becomes performance-sensitive, use either:

- multiple files in one ExifTool invocation; or
- ExifTool `-stay_open` worker integration.

Do not introduce a long-lived process before profiling proves it useful.

## Read/write policy

### JPEG / TIFF

Read and write EXIF/IPTC/XMP where supported.

### PNG

Read/write supported metadata where ExifTool and the format permit it.

### WebP

Read/write supported metadata where ExifTool and the format permit it.

### AVIF / HEIC / HEIF

Read/write EXIF/XMP and other supported metadata according to ExifTool's format capabilities. Not every metadata family can be created in every container, so the adapter must expose capability checks.

### RAW

Mediarama reads metadata from RAW files but does not destructively rewrite uploaded RAW originals by default.

For editable metadata export, prefer:

- XMP sidecar;
- generated derivative/export copy;
- explicit future versioned original-replacement operation only if ever required.

## C2PA / authenticity metadata

ExifTool can inspect C2PA/JUMBF information, but Mediarama must not silently claim it can rewrite authenticity metadata.

Preservation/removal behavior must be explicit in export policy and tested separately.

## Security

ExifTool runs as an external process under the worker context.

Requirements:

- no shell interpolation;
- pass arguments as an argv list;
- materialize input into a controlled temporary directory when storage is remote;
- enforce timeout;
- enforce output-size limits;
- clean temporary files;
- treat parser failures as processing failures, not application crashes.

## Consequences

- ExifTool becomes a production dependency for metadata-capable installations.
- Metadata format support can evolve independently of PHP libraries.
- Search remains PostgreSQL-backed and never depends on ExifTool during normal page requests.
- Export capability is format-aware rather than assumed universal.
