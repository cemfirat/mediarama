# Privacy-safe inherited metadata audit

Status: **active research**  
Date: 2026-09-27  
Tracks: #87, #88

## Why this audit exists

Mediarama's embedded-copy exporter begins with a byte-for-byte copy of the source file and then applies ExifTool writes.

That preserves image data and avoids recompression, but it also means every source metadata field survives unless the export policy explicitly removes or replaces it.

PR #85 fixed the known canonical descriptive-location leak. It did not prove that unknown inherited metadata is safe.

The same mechanism exposes a second correctness problem for the Custom profile: unselected source metadata can survive because Custom currently writes selected fields without first defining the inherited-metadata boundary. Issue #88 tracks that separate user-facing contract.

## External research

### ExifTool: deletion is format-sensitive

ExifTool FAQ #32 explicitly warns that removing all metadata is not safe for every file type. For JPEG, it recommends deleting metadata while preserving color-space information:

`exiftool -ext jpg -all= --icc_profile:all -tagsfromfile @ -colorspacetags DIR`

The FAQ explains that color information may be required to preserve rendering.

Reference:

- https://exiftool.org/faq.html#Q32

ExifTool's shortcut documentation defines `ColorSpaceTags` as:

- `ExifIFD:ColorSpace`
- `ExifIFD:Gamma`
- `InteropIFD:InteropIndex`
- `ICC_Profile`

Reference:

- https://exiftool.org/TagNames/Shortcuts.html

Orientation is not part of that shortcut. Removing EXIF orientation from an unchanged JPEG can change how the image is displayed, so orientation needs an explicit preservation/normalization decision.

### TIFF is structurally different

ExifTool FAQ #7 explains that TIFF stores the main image in IFD0. Deleting all EXIF information cannot be treated like JPEG because removing the main IFD would destroy the image. `-all=` therefore does not imply the same privacy boundary on TIFF.

Reference:

- https://exiftool.org/faq.html#Q7

This means a single generic "delete all metadata" argv cannot be assumed to provide equivalent semantics for JPEG and TIFF.

### PNG contains rendering-related chunks alongside metadata

ExifTool's PNG documentation includes ICC profiles, gamma, sRGB rendering intent and primary chromaticities in addition to XMP/EXIF/textual metadata.

Reference:

- https://exiftool.org/TagNames/PNG.html

A privacy scrub must not accidentally degrade color rendering while removing textual/XMP/EXIF identifiers.

### WebP can carry EXIF, XMP and ICC

ExifTool documents write support for EXIF, XMP and ICC_Profile in WebP/RIFF.

Reference:

- https://exiftool.org/TagNames/RIFF.html

### AVIF/HEIC are QuickTime-family containers

ExifTool's QuickTime documentation covers HEIC and AVIF and exposes structural item properties such as rotation, mirroring and color representation.

Reference:

- https://exiftool.org/TagNames/QuickTime.html

Those properties are part of faithful rendering and must not be conflated with user/privacy metadata.

### IPTC has a much larger identifying surface than Mediarama's canonical subset

The IPTC Photo Metadata standard includes creator/contact information, locations, people, rights parties, names and identifiers.

Reference:

- https://iptc.org/standards/photo-metadata/iptc-standard/

A denylist based only on fields Mediarama currently normalizes cannot prove that unknown inherited IPTC/XMP metadata is privacy-safe.

## Sensitive inherited classes to test

The audit must include representative source data for:

### Location

- EXIF GPS
- XMP GPS
- IPTC/XMP sublocation
- city/state/country
- Location Created / Location Shown style fields where supported

### Person / contact / attribution

- EXIF OwnerName / CameraOwnerName
- IPTC Creator / By-line
- XMP creator
- IPTC Core creator contact fields
- person-shown / model-related fields
- email, phone, URL and postal address fields

### Device / unique identifiers

- body/camera SerialNumber
- LensSerialNumber
- ImageUniqueID
- device owner name
- MakerNotes and vendor-private data
- XMP document / instance identifiers
- workflow/application identifiers where they can become tracking identifiers

### Hidden / arbitrary source metadata

- arbitrary PNG text
- unknown XMP namespaces
- Photoshop/IPTC resource metadata
- comments and free-form user fields

## Rendering-critical state to protect

A strong privacy implementation must preserve image fidelity without preserving arbitrary source metadata.

At minimum the real-format harness must verify:

- file remains decodable;
- width/height remain valid;
- orientation/display orientation is preserved;
- ICC/color-space semantics are preserved where present;
- PNG gamma/sRGB/color chunks are not accidentally degraded;
- AVIF/HEIC rotation/mirroring/color item properties remain correct;
- animation/image structural chunks are not treated as privacy metadata.

## Architecture direction

### Reject an ever-growing denylist

A denylist cannot establish a durable Privacy-safe guarantee because unknown namespaces and vendor fields survive by default.

### Prefer an allowlist/rebuild boundary

For ordinary generated copies, the target model is:

1. copy immutable source bytes to a generated artifact;
2. remove inherited **user/privacy metadata** according to a format-proven scrub strategy;
3. preserve only rendering-critical container/color/orientation state that has been explicitly reviewed;
4. write the canonical fields intentionally retained by the selected export profile;
5. read the resulting artifact back with ExifTool and format decoders in CI.

RAW remains different: Mediarama does not scrub/rewrite the RAW original. It creates a fresh XMP sidecar from canonical policy output.

### Do not pretend every format has the same scrub command

The policy guarantee is shared, but the safe mechanism may be format-specific.

A format should not claim the stronger Privacy-safe guarantee until the real-file gate demonstrates that both conditions hold:

- sensitive inherited metadata is absent;
- rendering-critical state is preserved.

If a supported container cannot meet both without recompression or damage, fail closed for that profile rather than produce an artifact with a misleading privacy guarantee.

## User-facing guarantee under evaluation

A realistic guarantee is narrower than "anonymous":

> Privacy-safe removes inherited hidden metadata and structured location/device/contact identifiers according to the supported format policy, while preserving explicitly documented visible canonical fields and rendering-critical image state.

If title, description, creator or copyright remain, product wording must state that these visible canonical values are retained and may themselves contain personal information.

Full anonymization is not promised unless all retained free-form fields are also removed.

## Required evidence before implementation is accepted

For each writable embedded-copy format currently declared by Mediarama:

- JPEG
- TIFF
- PNG
- WebP
- AVIF
- HEIC/HEIF

CI must seed representative sensitive source metadata, run the production scrub path, then prove:

1. immutable source checksum is unchanged;
2. result is decodable;
3. dimensions/orientation semantics remain correct;
4. relevant color-management state remains correct;
5. seeded sensitive metadata cannot be found through normal or duplicate ExifTool tag reads;
6. intended retained canonical fields round-trip;
7. unknown/arbitrary metadata used by the fixture does not silently survive when the policy says it must not.

Only after this evidence should the Privacy-safe copy implementation move from denylist to allowlist/rebuild semantics.
