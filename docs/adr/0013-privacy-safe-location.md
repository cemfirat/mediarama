# ADR-0013: Remove descriptive location from Privacy-safe metadata exports

- Status: Accepted
- Date: 2026-09-27

## Context

Mediarama stores location in two separate canonical forms:

- exact coordinates: `latitude` / `longitude`;
- human-readable descriptive location: `location_name`.

The Privacy-safe export profile already removes exact GPS coordinates and camera/device serial identifiers, but it previously wrote the current canonical `location_name` back into exported copies and RAW XMP sidecars.

That is not a reliable privacy boundary.

A descriptive location is not inherently coarse. The same field may contain a city or region, but it may also contain a home, school, venue, building, landmark or street-level sublocation. Mediarama currently has no trustworthy machine-readable granularity attached to `location_name`.

IPTC's own photo-metadata guidance describes sublocation as the narrowest location level and notes that it may identify a well-known place or specific structure. The IPTC model also distinguishes Location Created from Location Shown because human-readable location semantics are not interchangeable.

Mediarama must therefore not infer that an arbitrary descriptive location is safe merely because exact GPS is absent.

## Decision

For the `privacy_safe` export profile:

1. remove exact GPS coordinates;
2. remove the canonical human-readable `location_name`;
3. actively clear the embedded XMP/IPTC location tags used by Mediarama's canonical location mapping so an existing source value cannot survive a copy export;
4. apply the same rule to ordinary embedded-metadata export copies and RAW XMP sidecars;
5. retain non-location descriptive fields such as title, description, creator and copyright according to the existing profile;
6. do not reverse-geocode, round, generalize or otherwise derive a city/region from exact GPS;
7. do not attempt to classify free-form `location_name` text as broad or precise.

The `current` profile continues to write the canonical descriptive location and exact coordinates.

The `custom` profile may include `location_name` only when that field is explicitly selected. Selecting descriptive location does not implicitly include GPS.

## Why not retain city/region automatically

Mediarama does not currently store a structured precision level for `location_name`.

Trying to infer that "Vienna" is safe while "Home" or a venue name is not would create a heuristic privacy promise that cannot be enforced reliably across languages, imported data and user-edited free text.

If coarse-location export becomes a product requirement later, it needs an explicit structured model and user-visible policy. It must not be synthesized silently from exact coordinates by the Privacy-safe profile.

## Consequences

Positive:

- Privacy-safe no longer leaks Mediarama's canonical descriptive location;
- copy exports and RAW sidecars use one shared policy boundary;
- the profile does not make unsupported assumptions about location precision;
- users can still opt into descriptive location through Custom exports.

Trade-offs:

- a broad city/region is also removed even when a user might consider it harmless;
- users who want descriptive location must choose Current or explicitly include it in Custom;
- this decision does not by itself prove that every unknown third-party metadata field in an arbitrary source file is privacy-safe; broader inherited-metadata hardening remains a separate export-boundary concern.

## Verification

Regression coverage must prove:

- Current retains descriptive location and coordinates;
- Privacy-safe removes descriptive location and coordinates from ordinary export copies;
- Privacy-safe removes descriptive location and coordinates from RAW XMP sidecars;
- Custom can explicitly include `location_name` without implicitly including GPS.

## References

- Issue #68
- IPTC Photo Metadata User Guide: https://www.iptc.org/std/photometadata/documentation/userguide/
