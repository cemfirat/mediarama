# Metadata Workspace

Status: inspection foundation + explicit single-asset descriptive editing  
Tracking: #91

The Metadata Workspace is the authenticated professional surface over Mediarama's existing complete source metadata snapshot, normalized canonical fields and per-field provenance.

The source-inspection layer remains deliberately read-only. The first write slice adds explicit owner-only editing for a bounded set of canonical descriptive fields without changing the immutable source snapshot.

## Information architecture

The workspace separates three concepts that must never be visually conflated:

1. **Source snapshot** — metadata extracted from the immutable original.
2. **Current Mediarama value** — normalized/project metadata currently used by Mediarama.
3. **Provenance** — where the current canonical value came from, such as embedded metadata, import, migration, user edit or automation.

A user-edited current title must therefore never look as if it came from EXIF/IPTC/XMP in the original.

## Source sections

The stored source snapshot is presented in stable groups:

- EXIF / Camera;
- IPTC / Descriptive;
- XMP / Workflow;
- ICC / Color;
- File / Technical;
- Other source metadata for unexpected/legacy top-level values.

Unknown tags remain visible. Nested/multi-value source values are rendered read-only instead of being discarded or flattened into an invented canonical field.

The source snapshot remains the data captured by the metadata inspection pipeline. Opening the workspace never re-runs ExifTool and never mutates the original file.

## Current values and provenance

The first workspace surface shows Mediarama's existing normalized fields, including descriptive, capture, camera/lens and location values, alongside their recorded provenance.

Missing provenance is shown explicitly as not recorded rather than guessed.

The first editing slice supports the canonical descriptive fields `title`, `description`, `creator`, `copyright` and `location_name`.

Every field uses an explicit **Keep / Set / Clear** operation:

- **Keep** leaves the canonical field and provenance untouched;
- **Set** validates a bounded non-blank value, writes the canonical field and records provenance `user`;
- **Clear** deliberately writes `null` and records provenance `user`.

A blank Set value is rejected rather than being interpreted as Clear. This is the same semantic boundary required for future batch editing.

Source JSON, original bytes and arbitrary raw tags remain read-only. Revert-to-source is not guessed from raw tag names; it requires a separate typed canonical-to-source mapping slice.

## Authorization and privacy

Raw source inspection is owner-only in this foundation.

Normal Library access may include MediaAssets visible through Collection grants or public visibility, but that does not imply permission to inspect private embedded metadata. The Library therefore exposes the Metadata action only when the current actor owns the MediaAsset, and direct non-owner workspace requests fail closed.

The workspace is authenticated, `private, no-store` and `noindex, nofollow`.

Exact GPS and likely identity/contact/device fields are visibly marked privacy-sensitive. This marking is presentation guidance; it does not make those fields public and does not weaken export/publication policy.

Storage keys are not exposed by the workspace.

## Immutable-original rule

The workspace reads persisted metadata state only.

It does not:

- rewrite the uploaded original;
- alter source snapshot JSON;
- write arbitrary raw/vendor tags;
- change ACL, visibility or publication state;
- trigger metadata export.

Opening the page remains non-mutating. Explicit edit POSTs may change only the bounded canonical fields above. Title/description changes advance the truthful public-content timeline only when the MediaAsset is currently publicly reachable; private creator/copyright/location edits do not fabricate public SEO timestamps.

## Editing and batch direction

Future write slices must operate on canonical/project metadata, not on the immutable original.

Batch operations must make the intended operation explicit per field:

- replace;
- append;
- remove;
- leave unchanged.

Blank input must never implicitly mean "erase this field across every selected MediaAsset".

Clear and revert-to-source semantics must be field-aware. Raw unknown/vendor/structural tags remain read-only unless a later schema explicitly declares safe write semantics.

## Extensibility

The stable source groups are not a claim that every professional field belongs in the core MediaAsset schema.

Future #93 metadata profiles may add typed sections, validation and editors while the generic workspace continues to preserve and inspect unknown source namespaces. Domain-specific profiles must not require redesigning the raw/source inspection boundary.
