# ADR-0014: Privacy-safe copy exports rebuild metadata from an allowlist

- Status: Accepted
- Date: 2026-09-27

## Context

ADR-0013 removed exact and descriptive location from the Privacy-safe export profile.

That review exposed a broader boundary in ordinary metadata-copy exports:

`ExifToolMetadataWriter` first copies the original image container and then applies metadata edits.

A denylist of known sensitive tags is not enough for a profile called Privacy-safe. A source may carry EXIF/IPTC/XMP fields that Mediarama does not map canonically and therefore cannot classify reliably. Unknown contact, workflow, history or vendor-specific metadata must not remain merely because the application did not know its tag name.

RAW XMP sidecars are different: they are generated as new metadata artifacts and do not inherit an original image container.

ExifTool's documented safe-removal guidance also warns that indiscriminate metadata removal can alter image appearance when ICC/color-space information is removed. Orientation can likewise be display-critical when source pixels are stored unrotated.

## Decision

For ordinary image copies using the `privacy_safe` profile, Mediarama uses an allowlist boundary:

1. remove inherited metadata with ExifTool before canonical Privacy-safe fields are written;
2. preserve/re-copy rendering-relevant color-space metadata;
3. preserve ICC profiles;
4. preserve image orientation;
5. preserve X/Y resolution and resolution-unit semantics;
6. write only the canonical fields allowed by the Privacy-safe policy after the scrub;
7. never fall back to returning the unsanitized copied container if ExifTool fails.

The shared canonical policy then adds the current title, description, creator and copyright while continuing to remove GPS and descriptive location according to ADR-0013.

Unknown inherited EXIF/IPTC/XMP metadata is therefore removed by default rather than retained by default.

The `current` profile intentionally keeps inherited source metadata and overlays current canonical values.

The `custom` profile keeps its existing explicit-field behavior and is not silently converted into Privacy-safe.

RAW sidecars do not run the inherited-container scrub because there is no copied source metadata to inherit.

## Rendering-safety boundary

The scrub is not equivalent to blindly erasing every non-pixel structure.

Mediarama follows ExifTool's documented color-preservation approach:

- remove general metadata;
- exclude/preserve the ICC profile;
- copy standard color-space tags from the source snapshot.

Orientation and density tags are also retained because removing them can change display/print semantics without changing encoded pixels.

CI uses a real ICC fixture and real image formats to verify the output rather than assuming ExifTool behaves identically across containers.

## Supported copy formats

The existing ExifTool copy-export matrix remains:

- JPEG;
- TIFF;
- PNG;
- WebP;
- AVIF;
- HEIC/HEIF.

Privacy-safe support for a format is valid only while the real runtime integration gate proves:

- export succeeds on the deployed ExifTool runtime;
- inherited private XMP metadata does not survive;
- canonical allowed metadata is re-written;
- location/GPS remains absent;
- rendered pixel/orientation signature is unchanged;
- JPEG ICC and orientation preservation remains verified.

If a format loses that capability, CI must fail instead of silently returning a weaker Privacy-safe copy.

## Consequences

Positive:

- unknown inherited metadata is fail-closed instead of silently trusted;
- the privacy contract no longer depends on maintaining an exhaustive denylist;
- rendering-critical color/orientation behavior is explicitly protected;
- RAW sidecars and copied containers have separate, accurate boundaries;
- immutable originals remain unchanged.

Costs:

- Privacy-safe copies no longer preserve arbitrary source metadata that is not explicitly allowed;
- format/runtime upgrades require real-file revalidation;
- Current and Privacy-safe exports intentionally diverge more strongly.

## References

- Issue #86
- Issue #68 / ADR-0013
- ExifTool FAQ #32: https://exiftool.org/faq.html
