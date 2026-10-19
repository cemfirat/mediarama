# Metadata Workspace

Status: inspection foundation + explicit single-asset descriptive editing + typed source revert  
Tracking: #91

The Metadata Workspace is the authenticated professional surface over Mediarama's existing complete source metadata snapshot, normalized canonical fields and per-field provenance.

The source-inspection layer remains deliberately read-only. Current write slices add explicit owner-only editing and typed revert-to-source for a bounded set of canonical descriptive fields without changing the immutable source snapshot.

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

The workspace shows Mediarama's normalized fields, including descriptive, capture, camera/lens and location values, alongside their recorded provenance.

Missing provenance is shown explicitly as not recorded rather than guessed.

The current editing surface supports the canonical descriptive fields `title`, `description`, `creator`, `copyright` and `location_name`.

Every field uses an explicit **Keep / Set / Clear / Revert** operation:

- **Keep** leaves the canonical field and provenance untouched;
- **Set** validates a bounded non-blank value, writes the canonical field and records provenance `user`;
- **Clear** deliberately writes `null` and records provenance `user`;
- **Revert** restores only an unambiguous value resolved through the explicit canonical-to-source mapping and records provenance `embedded`.

A blank Set value is rejected rather than being interpreted as Clear. This is the same semantic boundary required for future batch editing.

## Typed source mapping

Revert-to-source never searches arbitrary raw tag names.

The same explicit field mapping used by the ExifTool parser defines source candidates and their priority:

- title: XMP Dublin Core title, generic XMP title, IPTC object name;
- description: XMP description, IPTC caption, EXIF image description;
- creator: XMP creator, IPTC by-line, EXIF artist;
- copyright: XMP rights, IPTC copyright notice, EXIF copyright;
- coarse location: XMP/IPTC location and IPTC sub-location.

The persisted source snapshot predates this revert workflow and may collapse the namespace prefix of the first tag in a group. The workspace therefore accepts a legacy snapshot only when every explicitly mapped candidate present resolves to the same non-blank value. Conflicting XMP/IPTC/EXIF candidates fail closed and the UI disables Revert for that field.

The UI previews the exact value that will be restored and the mapped source keys before submission. Execution resolves the source value again from the persisted snapshot, so a client cannot supply or substitute its own revert value.

Source JSON, original bytes and arbitrary raw tags remain read-only.

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

Opening the page remains non-mutating. Explicit edit POSTs may change only the bounded canonical fields above. Title/description value changes advance the truthful public-content timeline only when the MediaAsset is currently publicly reachable; provenance-only restoration and private creator/copyright/location edits do not fabricate public SEO timestamps.

## Editing and batch direction

Future write slices must operate on canonical/project metadata, not on the immutable original.

Batch operations must make the intended operation explicit per field:

- replace;
- append;
- remove;
- revert to an unambiguous typed source value;
- leave unchanged.

Blank input must never implicitly mean "erase this field across every selected MediaAsset".

Clear and revert-to-source semantics remain field-aware. Raw unknown/vendor/structural tags remain read-only unless a later schema explicitly declares safe write semantics.

## Extensibility

The stable source groups are not a claim that every professional field belongs in the core MediaAsset schema.

Future #93 metadata profiles may add typed sections, validation and editors while the generic workspace continues to preserve and inspect unknown source namespaces. Domain-specific profiles must not require redesigning the raw/source inspection boundary.
