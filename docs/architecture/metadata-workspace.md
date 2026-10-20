# Metadata Workspace

Status: inspection foundation + single-asset editing + confirmed batch keyword editing
Tracking: #91

The Metadata Workspace is the authenticated professional surface over Mediarama's existing complete source metadata snapshot, normalized canonical fields, relational keywords and per-field provenance.

The source-inspection layer remains deliberately read-only. The current write slices add explicit owner-only editing for a bounded set of canonical descriptive fields and relational keywords without changing the immutable source snapshot.

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

The workspace shows Mediarama's existing normalized fields, including descriptive, capture, camera/lens and location values, alongside their recorded provenance.

Missing provenance is shown explicitly as not recorded rather than guessed.

The scalar editing slice supports the canonical descriptive fields `title`, `description`, `creator`, `copyright` and `location_name`.

Every scalar field uses an explicit **Keep / Set / Clear / Revert to source** operation:

- **Keep** leaves the canonical field and provenance untouched;
- **Set** validates a bounded non-blank value, writes the canonical field and records provenance `user`;
- **Clear** deliberately writes `null` and records provenance `user`;
- **Revert to source** resolves only through the typed canonical-to-source mapping, restores the persisted source value and records provenance `embedded`.

A blank Set value is rejected rather than being interpreted as Clear. This is the same semantic boundary required for future batch editing.

Source JSON, original bytes and arbitrary raw tags remain read-only. Revert never searches arbitrary raw tags or trusts a client-supplied source value.

## Relational keywords

Current keywords use the existing normalized `tags` / `media_tags` relationship rather than a parallel text or JSON column. Each current membership retains its source marker, such as `manual`, `embedded` or `coppermine`.

Keyword editing is a separate atomic form with explicit operations:

- **Replace** sets the complete desired keyword set and records memberships as `manual`;
- **Append** adds only missing keywords and preserves the source marker of existing memberships;
- **Remove** removes only the named current keywords;
- **Clear all** deliberately removes every current keyword;
- **Revert to source** replaces the current set with the explicitly mapped source keywords and records memberships as `embedded`.

Input is one keyword per line. Keyword matching and de-duplication are case-insensitive, while the first supplied display spelling is retained when a new global tag is created. A request supports at most 200 keywords and each keyword must contain 1–160 printable characters.

Blank input is rejected for Replace, Append and Remove. It never means Clear.

The typed source priority is XMP Dublin Core `Subject`, then generic XMP `Subject`, then IPTC `Keywords`. Persisted legacy snapshots may contain collapsed namespace labels; source revert is available only when all mapped candidates present describe the same keyword set. Conflicting candidates fail closed.

Keyword mutations update the MediaAsset's private workspace timestamp but do not rewrite the immutable source snapshot. Public publication-timeline implications of future batch/tag-driven Smart Collection changes remain a separate policy slice.

## Batch keyword editing

The Library can select up to 200 requester-owned MediaAssets for one keyword operation. Assets not owned by the requester cannot be selected for this workflow, and the server independently verifies ownership for every submitted ID.

The batch editor supports explicit **Leave unchanged**, **Replace**, **Append**, **Remove** and **Clear all** operations. Leave unchanged is the safe default. Append, Replace and Remove require one or more non-blank keywords; blank input never means deletion. Keyword input retains the single-asset limits of 200 values, 1–160 printable characters per value, case-insensitive matching and de-duplication.

Before writing, the owner reviews each selected asset's current keyword set, resulting set and additions/removals. Confirmation revalidates the entire selection and applies changes in one transaction; if any asset is missing, deleted or not owned by the requester, none are changed. Assets with no effective change are skipped, and an all-no-op batch is rejected.

Append preserves existing membership provenance and creates new memberships as `manual`. Replace explicitly makes all resulting memberships `manual`; Remove deletes only matching memberships; Clear all deliberately deletes every current membership. These operations do not change source metadata, immutable originals, ACLs, publication state or storage identity.

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

Opening the page remains non-mutating. Explicit descriptive edit POSTs may change only the bounded canonical fields above. Title/description value changes advance the truthful public-content timeline only when the MediaAsset is currently publicly reachable; private creator/copyright/location edits and provenance-only reverts do not fabricate public SEO timestamps.

Keyword POSTs mutate only normalized tag memberships and the private MediaAsset update timestamp.

## Editing and batch direction

Future batch write slices must operate on canonical/project metadata, not on the immutable original.

Batch operations must make the intended operation explicit per field:

- replace;
- append;
- remove;
- leave unchanged.

Blank input must never implicitly mean "erase this field across every selected MediaAsset".

The single-asset keyword workflow established the operation semantics; the Library batch workflow adds owner-only selection, per-asset preview and transactional confirmation.

Raw unknown/vendor/structural tags remain read-only unless a later schema explicitly declares safe write semantics.

## Extensibility

The stable source groups are not a claim that every professional field belongs in the core MediaAsset schema.

Future #93 metadata profiles may add typed sections, validation and editors while the generic workspace continues to preserve and inspect unknown source namespaces. Domain-specific profiles must not require redesigning the raw/source inspection boundary.
