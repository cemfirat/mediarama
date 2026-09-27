# Metadata Format Support Matrix

Status: **initial ExifTool-backed policy**
Date: 2026-09-25

This table describes Mediarama policy, not merely theoretical container capabilities.

| Format | Extract | Write export copy | Original mutation | Mediarama policy |
| --- | --- | --- | --- | --- |
| JPEG | Yes | Yes | No by default | Full priority |
| TIFF | Yes | Yes | No by default | Full priority |
| PNG | Yes | Yes where supported | No | Supported |
| WebP | Yes | Yes where supported | No | Supported |
| AVIF | Yes | Yes where supported | No | Supported, capability-aware |
| HEIC/HEIF | Yes | Yes where supported | No | Supported, capability-aware |
| DNG | Yes | Sidecar/export preferred | No | RAW-safe |
| CR2/CR3 | Yes | Sidecar/export preferred | No | RAW-safe |
| NEF/NRW | Yes | Sidecar/export preferred | No | RAW-safe |
| ARW | Yes | Sidecar/export preferred | No | RAW-safe |
| RAF | Yes | Sidecar/export preferred | No | RAW-safe |
| ORF/RW2/PEF/etc. | Yes where ExifTool supports | Sidecar/export preferred | No | RAW-safe |
| XMP sidecar | Yes | Yes | N/A | Canonical RAW companion |
| MP4/MOV | Yes | Later phase | No | Video metadata phase |
| MP3/audio | Yes where supported | Later phase | No | Audio metadata phase |

## Important limitation

"Writable" does not mean every metadata family is valid in every file type.

For example, a container may support XMP but not legacy IPTC IIM, or may permit writing an existing profile but not creating one.

Therefore the exporter must use **capability-based writing** rather than blindly copying every tag group.

## Priority order

For canonical descriptive metadata, prefer modern interoperable namespaces:

1. XMP/IPTC Photo Metadata fields where appropriate;
2. EXIF for camera/technical fields;
3. legacy IPTC IIM only where format/tool compatibility justifies it.

Mediarama's database remains the canonical editable layer regardless of how a particular export file can encode those fields.

## Real-file verification

CI exercises the actual ExifTool inspection and export adapters against generated real files for JPEG, TIFF, PNG, WebP, AVIF and HEIC.

For every format the test verifies:

- XMP descriptive and GPS metadata can be extracted through ExifTool's family-1 group names;
- the Current export profile writes Mediarama canonical metadata into a copy and round-trips through the normal inspector;
- the immutable source checksum is unchanged;
- the Privacy-safe profile removes GPS while retaining descriptive metadata.

This is a runtime integration gate rather than a parser-only fixture. If the declared CI/runtime toolchain loses one of these format capabilities, the repository gate fails instead of silently downgrading support.
