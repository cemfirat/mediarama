# Organization Assistant

Status: proposal foundation + deterministic metadata producer
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

The current foundation does not call providers.

Provider capabilities, external-send approval and privacy/cost preflight are
implemented separately in #135.

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

The provider adapter in #135 must additionally prevent sensitive fields from
being sent externally by default and must show a deliberate privacy/cost
preflight before any request.

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

- #135 — provider capability adapter + privacy/cost preflight;
- #136 — authenticated review UI + atomic accept/edit/reject.

Hybrid Collection pins/exclusions remain #18.
