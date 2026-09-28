# ADR-0008: Support manual and metadata-driven Smart Collections

- Status: Accepted
- Date: 2026-09-26

## Context

Mediarama must support two different ways of organizing media:

1. deliberate editorial curation;
2. automatic organization derived from normalized media metadata.

A purely manual hierarchy becomes expensive for large libraries. A purely automatic hierarchy creates the opposite problem: hundreds of overlapping, low-value albums such as one collection per year, camera, lens, tag, place or EXIF value.

Mediarama should make metadata powerful without turning metadata itself into navigation.

## Decision

Mediarama will support explicit **collection modes**.

### Manual Collection

A manual Collection stores explicit media membership.

This remains the stable default for:

- curated galleries;
- portfolios;
- client/event selections;
- editorial ordering;
- manually assembled public pages.

### Smart Collection

A Smart Collection stores a validated rule/query rather than explicit membership.

Its media set is resolved dynamically from Mediarama's normalized searchable metadata.

Examples:

- capture date in September 2026;
- media type is video;
- location name is Vienna;
- camera model is Nikon Z 8;
- lens is 35 mm;
- tag contains wedding;
- rating is at least 4;
- combinations using AND/OR.

MediaAssets are never duplicated because they appear in several Smart Collections.

## Product rule

Mediarama will **not automatically create a public Collection for every metadata value**.

Metadata first powers:

- search;
- filtering;
- faceting;
- suggestions.

A useful filter can then be saved deliberately as a Smart Collection.

A future recommendation feature may suggest useful Smart Collections, but a suggestion is not itself a durable/public Collection.

## Stable identity

A Smart Collection has its own stable identity independent of its result set:

- UUID;
- title;
- slug;
- description;
- visibility;
- cover;
- SEO metadata;
- saved rule.

Changing the rule changes membership. It does not change the public Collection identity or canonical URL.

## Rule representation

The first implementation should use a small validated Mediarama rule language persisted as structured JSON.

The rule language should support:

- AND / OR groups;
- equality;
- inclusion;
- ranges;
- date ranges;
- numeric comparison;
- tag membership;
- media type;
- normalized metadata fields.

The application owns the rule grammar. Raw SQL is never stored as a Smart Collection rule.

Rules should query normalized/indexed fields where possible instead of arbitrary raw EXIF/XMP JSON.

## Metadata boundary

Suitable first-class rule fields include:

- capture date;
- media type;
- tags;
- creator;
- camera make/model;
- lens;
- rating;
- orientation/aspect ratio;
- location name or explicitly public coarse location;
- owner;
- future approved custom fields.

Exact GPS coordinates and other potentially sensitive embedded metadata are excluded from normal Smart Collection rules unless a later privacy design explicitly enables them.

## Authorization

Collection authorization is evaluated before returning Smart Collection results.

Every returned MediaAsset must also satisfy its own visibility/access requirements.

A Smart Collection is never a shortcut around media authorization.

## Membership persistence

For the first Smart Collection version:

- manual membership remains in `collection_media`;
- Smart Collection membership is computed from its saved rule;
- Smart Collection results are not copied into `collection_media` merely to simulate dynamic membership.

Caching/materialized result sets may be added later if measurement proves they are required.

## Hybrid Collections

A Hybrid mode is deliberately deferred.

A future Hybrid Collection may combine:

- a Smart rule;
- manual pins;
- manual exclusions;
- curated ordering/featured items.

Hybrid behavior should receive its own explicit design instead of overloading the first Smart Collection implementation.

## Consequences

- large libraries can be useful without deep category maintenance;
- manual editorial Collections remain predictable;
- metadata becomes a query capability instead of an uncontrolled navigation generator;
- one MediaAsset can appear in many views without duplication;
- Smart Collection membership may change when metadata changes;
- performance requires appropriate PostgreSQL indexes and deterministic pagination;
- SEO/indexability must be independent from whether a Collection is manual or smart.

## Related

- GitHub issue #14 — Smart Collections from metadata and saved rules
- GitHub issue #13 — integrated SEO, social metadata and media discovery


## V1 implementation boundary

The first implementation deliberately keeps Smart Collections inside the
authenticated library. It does not make Smart Collections public or indexable.

A Smart Collection belongs to a concrete owner. V1 dynamic membership starts
from that owner's MediaAssets and then applies the normal authenticated
MediaAsset visibility boundary for the requesting actor. This gives the saved
rule a stable library universe while preventing a viewer's unrelated personal
MediaAssets from appearing merely because they happen to match somebody else's
shared Smart Collection.

V1 rules are versioned JSON with a fixed allowlist:

- media type;
- capture time;
- creator;
- camera make/model;
- lens;
- coarse/location-name text;
- tag slug/name;
- average rating;
- orientation derived from width/height.

Exact GPS and arbitrary raw metadata/provenance paths are excluded.

The compiler owns every SQL fragment and binds every rule value. Rules are
limited to four group levels, 50 leaf predicates and 100 values per `in`
predicate.

Result ordering is deterministic:

1. capture time descending, nulls last;
2. record creation time descending;
3. UUID descending.

Manual Collections remain unchanged and continue to use `collection_media`. Upload/manual-add destinations also reject Smart Collections before persistence, and a database trigger provides a final invariant if an older write path attempts to add persisted membership anyway.
Converting a manual Collection that already has persisted membership into a
Smart Collection fails closed instead of silently discarding or reinterpreting
its curated membership.

Public Smart Collection routing, publication and SEO remain a separate
follow-up to #13/#14 after this deterministic resolver is proven.
