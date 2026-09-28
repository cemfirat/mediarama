# ADR-0016: Custom metadata export selection is authoritative

- Status: Accepted
- Date: 2026-09-27
- Tracks: #88
- Related: ADR-0013, ADR-0014

## Context

Mediarama has four metadata export profiles:

- Original;
- Current;
- Privacy-safe;
- Custom.

RAW XMP sidecars are created as fresh metadata artifacts, so a Custom sidecar naturally contains only the fields Mediarama writes.

Ordinary image-copy exports are different. They begin from a byte-for-byte copy of the immutable source. Before this decision, Custom wrote the selected canonical fields but left all other inherited source metadata untouched.

That made a request such as "Custom: title only" misleading: source GPS, creator, copyright, location or unknown metadata could remain in the copy even though the caller did not select it.

## Decision

Custom selection is authoritative for generated embedded-metadata copies.

A Custom copy:

1. copies the immutable source into a generated artifact;
2. applies the same proven inherited-metadata scrub foundation used by Privacy-safe copies;
3. preserves rendering-critical state outside the user metadata selection;
4. writes only explicitly selected supported canonical fields.

The immutable original is never rewritten.

## Rendering-critical state is not a user metadata field

Custom controls user/descriptive/canonical metadata, not the minimum technical state required to render the file faithfully.

The scrub therefore preserves the same reviewed infrastructure state as ADR-0014, including where applicable:

- ICC/color-space information;
- orientation;
- PNG gamma/sRGB rendering semantics;
- X/Y resolution and resolution unit;
- structural container/image data.

Omitting these from Custom `includedFields` does not request a visually degraded image.

## Supported Custom field vocabulary

The current canonical Custom vocabulary is explicit:

- `title`;
- `description`;
- `creator`;
- `copyright`;
- `location_name`;
- `latitude`;
- `longitude`.

Unknown field keys are rejected rather than silently ignored.

Duplicate keys are rejected.

An empty Custom field list is valid and means a sanitized generated copy with no selected canonical user metadata, while still preserving rendering-critical state.

Future canonical fields must be deliberately added to this vocabulary and tested before callers can select them.

## Location semantics

Descriptive location and GPS are independent selections.

Selecting:

`location_name`

does not implicitly include latitude/longitude.

Selecting:

`latitude`, `longitude`

does not implicitly include descriptive location.

This makes precise-location inclusion explicit.

## Profile comparison

### Original

Return immutable original bytes; no metadata rewrite.

### Current

Keep inherited source metadata and overlay current canonical Mediarama values.

### Privacy-safe

Scrub inherited source metadata and write the fixed Privacy-safe canonical allowlist.

### Custom

Scrub inherited source metadata and write only explicitly selected supported canonical fields.

## RAW sidecars

RAW originals remain immutable.

Custom RAW XMP sidecars are fresh artifacts and therefore do not run an inherited-container scrub. They use the same explicit Custom field vocabulary and write only selected canonical fields.

## Consequences

Positive:

- Custom now means what its API/UI says;
- unselected inherited GPS/location/creator/copyright/vendor metadata no longer survives by accident;
- unknown Custom field requests fail loudly;
- embedded copies and fresh RAW sidecars share the same selection semantics;
- rendering fidelity is independent of metadata selection.

Trade-offs:

- Custom no longer preserves arbitrary inherited metadata unless Mediarama exposes that metadata as an explicitly supported canonical field;
- expanding Custom requires explicit schema/policy work rather than accepting arbitrary tag names.

## Verification

Implementation must verify:

- all supported writable copy formats;
- unselected inherited metadata does not survive;
- explicitly selected canonical metadata is written;
- descriptive location and GPS remain independent;
- rendering signature remains stable;
- RAW sidecar Custom behavior remains consistent.
