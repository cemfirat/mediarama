# Organization Assistant

Status: proposal foundation + deterministic metadata producer + approval-gated AI provider boundary
Date: 2026-09-28

Mediarama's organization assistant is a review layer over the normal media,
Collection, Smart Collection and tag domains. It is not an autonomous curator.

The deterministic Smart Collection layer remains fully functional without AI.

## Analysis runs

An organization run belongs to the requesting user and snapshots a concrete set
of authorized MediaAsset UUIDs.

The current foundation accepts up to 50,000 unique MediaAssets per run so the
model is suitable for large libraries without persisting raw query/provider
state.

Run statuses:

- `draft` — producers may add proposals;
- `ready_for_review` — proposal generation completed with reviewable proposals;
- `no_suggestions` — analysis completed but all candidate groups stayed below usefulness thresholds;
- `failed`;
- `cancelled`.

Only `draft` runs accept new proposals.

A run becomes `ready_for_review` only when at least one proposal exists. A successful analysis with no useful proposals enters `no_suggestions` instead of remaining in an ambiguous draft state.

## Producers

### Metadata

`metadata` is the deterministic producer kind.

It carries no provider/model identity. The deterministic analyzer uses this
path directly, so an installation with no AI provider remains fully capable of
metadata-only organization suggestions.

### External/local AI

`ai_external` and `ai_local` record only minimal provider audit identity:

- provider name;
- model name;
- optional model version.

AI providers are optional and registered through a provider-neutral capability
adapter.

Each provider declares:

- a stable provider key;
- external vs local producer kind;
- provider/model/version audit identity;
- supported capabilities such as text reasoning, image understanding,
  embeddings, batch analysis or local inference;
- optional privacy and retention notes.

No provider is required for Mediarama to operate. The deterministic metadata
producer does not consult this registry.

## AI privacy/cost preflight

External/local AI analysis is approval-gated.

Before inference, Mediarama builds and persists a preflight that states:

- exact authorized MediaAsset count and media-type breakdown;
- requested provider capabilities;
- metadata-only vs metadata + presentation input mode;
- how many bounded presentation derivatives are available to send;
- creator/coarse-location opt-in state;
- fields that are excluded from provider input;
- a local cost estimate when the adapter can provide one without sending data;
- configured provider privacy/retention notes.

The persisted preflight starts in `pending_approval`. Provider inference is
not reachable until the requester deliberately approves it.

At execution time Mediarama revalidates authorization, provider/model identity,
capabilities and approved presentation availability. A changed or unavailable
scope fails closed.

The provider receives a narrow Mediarama DTO. It has no original filename,
source storage identity, raw metadata/provenance or exact GPS fields. Creator
and coarse location name are excluded by default and require explicit
preflight opt-in.

For image understanding, only existing generated presentation derivatives are
available. Mediarama uses a bounded approval-scoped gateway; it cannot fetch
unapproved media and never falls back to immutable originals.

Provider adapters return ordinary Mediarama proposal candidates. Raw provider
request/response bodies and credentials are not stored. Failures record a
stable sanitized failure code and do not create partial Collections, tags or
proposal runs.

## Proposal model

Each proposal is owned indirectly through its run and starts in
`pending_review`.

Current types:

- `smart_collection`;
- `manual_collection`;
- `tag`;
- `review_bucket`;
- `title_description`;
- `cover`.

Each payload is versioned and type-specific. Unknown/missing keys fail closed.

Smart Collection proposals contain an ordinary validated Smart V1 rule; they
cannot add exact-GPS or arbitrary raw-metadata predicates.

Proposal rationale is bounded to 5,000 characters.

A proposal carries one or more evidence entries, each classified as:

- `metadata` — deterministic evidence from Mediarama state;
- `inference` — an AI/model inference.

This distinction remains visible to the future review UI.

## Media scope

`organization_run_media` is the immutable run-scope snapshot.

Run creation first applies the normal authenticated MediaAsset access SQL. If
any requested MediaAsset is unavailable, creation fails as a whole.

`organization_proposal_media` must point both to its proposal/run and to a
MediaAsset already present in that run scope. Composite database foreign keys
enforce this relationship.

This is deliberately different from Collection membership. Proposal MediaAsset
rows never make a MediaAsset public and never create `collection_media`.

## Review safety

The first foundation implements explicit rejection.

Rejection:

- is requester-only;
- is idempotent;
- records review time;
- changes proposal state only;
- does not create/delete/edit Collections, tags or Collection membership;
- does not change visibility, ACLs or publication.

Acceptance is intentionally not implemented in this slice. #136 must apply
accepted proposals atomically through ordinary Mediarama application services
and revalidate current permissions/staleness before mutation.

## Privacy boundary

The persistence model has no columns for source storage paths, filenames, raw
metadata, exact GPS or provider request/response bodies.

Proposal payloads have strict application-owned schemas.

Evidence persists only source kind + bounded summary.

The AI provider boundary additionally prevents sensitive fields from being
sent by default and requires deliberate privacy/cost preflight approval before
provider inference.

## Limits

Current operational bounds:

- 50,000 MediaAssets per run;
- 50,000 affected MediaAssets per proposal;
- 200 proposals per run;
- 20 evidence items per proposal.

Media rows are inserted in bounded batches.

These are safety/operability limits rather than product recommendations.

## Deterministic metadata suggestions

The metadata-only producer reads only the authenticated run scope and a narrow
normalized snapshot:

- media type;
- capture time;
- camera model and lens;
- coarse location name;
- average rating;
- normalized tags;
- canonical dimensions where needed later;
- whether the MediaAsset already has Collection membership visible to the requesting actor.

It deliberately does not select source filenames, storage keys, raw metadata
JSON, exact latitude/longitude or hidden Collection titles.

The first deterministic planner uses a minimum support threshold of 5
MediaAssets and emits at most 20 proposals per run. Per-dimension caps and
exact affected-set de-duplication prevent one noisy metadata field from
flooding review.

Current deterministic candidates include:

- coarse location + capture-month Smart Collections;
- existing normalized tag Smart Collections;
- high-rated Smart Collections;
- media-type Smart Collections when more than one type is present;
- camera-model and lens Smart Collections;
- a review bucket for sufficiently large untagged/uncollected groups.

Every Smart suggestion is validated through the normal Smart V1 grammar before
persistence. Generation remains proposal-only; it never creates Collections,
tags, memberships, ACL changes or publication state.

The same authorized metadata snapshot produces the same proposal semantics.
Provider configuration is not consulted.

## Next slices

- #136 — authenticated review UI + atomic accept/edit/reject.

Provider-specific adapters can now be added behind the #135 capability/preflight
contract without changing Mediarama's proposal domain.

Hybrid Collection pins/exclusions remain #18.
