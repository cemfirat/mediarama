# Coppermine media-type model audit

Status: **migration policy resolved and CI-covered**
Date: 2026-09-25
Tracking: #11

Primary source: current 1.6/1.7 schemas, default data and media helper code.

## File-type registry

Coppermine uses a database-backed `filetypes` registry with:

- extension
- MIME
- content class
- player

Content classes are:

- image
- movie
- audio
- document

The runtime helper `cpg_get_type()` reads this table and combines it with administrator configuration:

- `allowed_img_types`
- `allowed_mov_types`
- `allowed_snd_types`
- `allowed_doc_types`

A file extension is accepted only when the extension exists in the registry **and** its content-class configuration allows it.

Display code may still load registered types that are not currently upload-allowed.

## Default breadth

Current 1.6 `sql/basic.sql` seeds **107 extensions**:

- 13 image
- 11 movie
- 7 audio
- 76 document

Examples span:

- JPEG/PNG/GIF/BMP/JPEG2000/PSD;
- MPEG/AVI/MOV/MP4/M4V/OGG video;
- MP3/MIDI/WMA/WAV/OGG audio;
- PDF/ZIP/RAR/7z and many office/OpenDocument formats.

Several older formats depend on legacy player identifiers such as WMP, QT, RMP and SWF.

## 1.7 additions

The 1.7 default registry adds:

- `webp` → image
- `webm` → movie
- `weba` → audio

No 1.6 default file-type row is removed in the checked data.

## Architecture implications

Coppermine is functionally broader than a still-photo gallery.

Mediarama's `MediaAsset` abstraction is therefore the correct direction, but Mediarama does **not** have to accept every historical Coppermine document/player format.

The final product decision should distinguish:

1. core visual media support;
2. first-class video/audio;
3. generic downloadable documents;
4. legacy formats accepted only during migration;
5. unsupported formats that block or warn.

## Security implication

Coppermine's classification is fundamentally extension-registry based.

Mediarama should retain its stronger approach:

- extension is advisory;
- inspect MIME/content;
- use decoder/prober validation;
- enforce server policy;
- never execute active media/document content;
- generated public derivatives are separate from originals.

## Migration policy

The source `filetypes` table is mutable **Coppermine runtime configuration**, not authoritative media truth.

Mediarama therefore does **not** migrate or trust the registry as its target allowlist.

For every actual source asset:

1. locate the real original file;
2. inspect its real content/MIME;
3. apply the Mediarama allowlist;
4. run structural/decoder validation where supported;
5. block migration before writes when the actual media cannot be accepted safely.

Consequences:

- a custom source extension may migrate when the real bytes are a supported Mediarama type;
- a Coppermine registry row cannot whitelist deceptive or unsupported real content;
- Coppermine `content` and legacy `player` choices are not target runtime settings;
- generic document formats remain unsupported until Mediarama deliberately adds a document model/policy;
- source registry MIME values and 1.7 `pictures.mime`/`pictures.ftype` remain audit evidence only.

CI covers both directions: a custom `.foo` row classified by Coppermine as a document still passes preflight when its bytes are a valid JPEG, while a `.jpg` that the source registry claims is an image is rejected when its actual MIME is `text/plain`.

## 1.7 media columns

1.7 adds `pictures.mime` and `pictures.ftype` and populates them during picture insertion.

This improves source-side type persistence but remains an extension of the existing `pictures` table.

Mediarama should continue deriving trusted media type from inspection rather than blindly importing these values.

They remain useful as source evidence and for discrepancy reporting.
