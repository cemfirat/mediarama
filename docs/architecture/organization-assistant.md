# Organization Assistant

Status: proposal foundation
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
- `ready_for_review` — proposal generation is complete;
- `failed`;
- `cancelled`.

Only `draft` runs accept new proposals.

A run becomes `ready_for_review` only when at least one proposal exists.

## Producers

### Metadata

`metadata` is the deterministic producer kind.

It carries no provider/model identity and is the basis for #134. An
installation with no AI provider can still use this path.

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

These are safety/operability limits rather than product recommendations. #134
must use quality/support thresholds to avoid generating hundreds of trivial
proposals merely because a metadata value exists.

## Next slices

- #134 — deterministic metadata-only suggestions;
- #135 — provider capability adapter + privacy/cost preflight;
- #136 — authenticated review UI + atomic accept/edit/reject.

Hybrid Collection pins/exclusions remain #18.
