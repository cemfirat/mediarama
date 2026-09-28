# Smart Collections

Status: private deterministic foundation + authenticated management
Date: 2026-09-28

Smart Collections are saved Mediarama-owned rules whose media membership is
resolved dynamically from normalized/queryable metadata.

They complement Manual Collections. They do not duplicate MediaAssets and they
do not write dynamic matches into `collection_media`.

## Modes

Collections currently have two explicit modes:

- `manual` — persisted membership in `collection_media`;
- `smart` — dynamic membership from a versioned validated `smart_rule`.

Existing and Coppermine-imported Collections remain Manual.

Database constraints and triggers prevent:

- a Smart Collection from carrying persisted `collection_media` membership;
- a Manual Collection with curated membership from silently entering Smart
  mode.

## V1 Smart rule grammar

The current rule grammar is versioned and application-owned. SQL is never
persisted.

Root and nested groups support:

- `and`;
- `or`.

V1 fields:

- media type;
- captured-at time;
- creator;
- camera make/model;
- lens;
- coarse location name;
- tag slug/name;
- average rating;
- orientation derived from width/height.

Exact latitude/longitude and arbitrary raw metadata/provenance JSON paths are
not valid rule fields.

Rules are bounded by nesting depth, predicate count and `in` list length.
Compilation uses fixed SQL fragments and bound values only.

## Dynamic membership

A private Smart Collection has an owner-based candidate universe.

The resolver first constrains candidates to MediaAssets owned by the Smart
Collection owner, then applies the same authenticated MediaAsset visibility
predicate used by Library search.

This prevents a viewer's unrelated personal MediaAssets from entering somebody
else's Smart Collection merely because the metadata happens to match.

Ordering is deterministic:

1. captured-at descending, null last;
2. record creation descending;
3. UUID descending.

Results remain dynamic. Metadata, tag and rating changes can therefore alter
membership without a membership rewrite.

## Authenticated management

The browser management surface lives under `/library` and requires an active
authenticated account in production.

Owners can:

- create private Smart Collections;
- inspect a human-readable rule summary;
- preview dynamic matches and count them;
- edit title + a simple top-level AND/OR rule;
- convert explicitly back to Manual mode;
- soft-delete a Smart Collection.

Browser mutations use Symfony CSRF protection.

Management reads/writes fail closed for non-owners. V1 management remains
private-only.

Nested rules remain valid and resolvable. The simple browser rule editor does
not flatten or rewrite nested groups; it leaves them read-only until a richer
nested rule-builder UI exists.

## Saving Library filters

The authenticated Library page uses the same request-to-criteria parser as the
JSON Library API.

A current Library filter may be saved as a Smart Collection only when the
mapping is lossless in V1.

Currently saveable:

- creator;
- camera make;
- camera model;
- lens;
- captured-from / captured-until.

Currently not silently serialized:

- full-text query;
- ISO range;
- exact coordinate-presence filter.

Those filters keep working for Library search, but the UI explains that they
cannot yet be saved as a Smart Collection rule.

## Publication

Smart Collections are deliberately private in this phase.

Rule matching is not publication and not authorization. Public/indexable Smart
Collections are tracked separately in #127. That work must apply the existing
public Collection + MediaAsset visibility/index boundaries after dynamic rule
resolution.

Temporary/ad-hoc Library filter URLs are not public SEO pages.

## AI and Hybrid behavior

AI organization (#16) may later propose Smart rules, but accepted proposals
must become ordinary Smart Collection state through these normal boundaries.

Hybrid pins/exclusions (#18) remain a separate later mode and do not alter the
V1 Smart rule/membership model.
