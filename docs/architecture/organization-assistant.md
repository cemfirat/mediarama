# Organization Assistant

Status: proposal foundation + deterministic metadata producer + approval-gated AI browser workflow + authenticated review/application
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

### Authenticated AI browser workflow

When one or more provider services are configured, the authenticated Library
adds an optional AI-assisted path beside the deterministic **Analyze selected**
action. An installation with zero providers renders no AI control and has no
degraded/error state.

The browser workflow is:

1. select an authorized MediaAsset scope;
2. choose one configured provider and only capabilities advertised by it;
3. choose metadata-only or metadata + bounded presentation input;
4. explicitly opt in to creator and/or coarse location when wanted;
5. prepare a persisted privacy/cost preflight without provider inference;
6. review provider/model/version, capability scope, exact media/type counts,
   presentation count, exclusions and configured cost/privacy/retention notes;
7. approve that exact preflight through CSRF-protected requester-only action;
8. execute provider inference once;
9. continue in the normal proposal review UI.

Prepare, approve and execute are separate authenticated POST boundaries. The
preflight review itself is private/no-store/noindex.

Browser scope is deliberately bounded to 200 selected MediaAssets per request
even though the lower-level organization domain can represent larger runs. This
keeps interactive requests predictable; larger/batch provider workflows can be
added later without weakening the same approval contract.

Provider execution reuses the #135 application boundary rather than moving
privacy logic into Twig/controllers. Authorization, provider identity,
capabilities and presentation availability are revalidated at execution time.
A failed/changed approved scope enters sanitized failed state and cannot be
silently resent; the user prepares a new preflight deliberately.


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

This distinction remains visible in the authenticated review UI.

## Media scope

`organization_run_media` is the immutable run-scope snapshot.

Run creation first applies the normal authenticated MediaAsset access SQL. If
any requested MediaAsset is unavailable, creation fails as a whole.

`organization_proposal_media` must point both to its proposal/run and to a
MediaAsset already present in that run scope. Composite database foreign keys
enforce this relationship.

This is deliberately different from Collection membership. Proposal MediaAsset
rows never make a MediaAsset public and never create `collection_media`.

## Authenticated review and application

Requester-owned analysis runs have a private UIkit review surface under
`/library/organization`. Review pages are authenticated, `noindex` and
`private, no-store`.

The UI shows:

- proposal type and presentation title;
- rationale;
- metadata-vs-inference evidence labels;
- affected MediaAsset count and a bounded authorized preview;
- human-readable Smart rules;
- provider/model plus the approved privacy/cost preflight when the run came
  from an AI provider.

Pending proposals may be edited only through their type-specific validated
payload. Smart proposal editing intentionally changes curated title/description
only; changing rule membership requires a new analysis rather than silently
rewriting the reviewed rule.

Review actions are requester-only and CSRF-protected. A proposal may be:

- accepted once;
- accepted as part of a selected set;
- rejected once or as part of a selected set;
- left pending.

Each accepted proposal is applied inside one database transaction and persists
the resulting resource identity on the proposal. A retry therefore returns the
same result instead of creating a duplicate.

Acceptance revalidates current ownership/authorization and the affected
MediaAsset scope immediately before mutation. Smart proposals additionally
re-evaluate their reviewed rule against the snapshotted run scope. A stale
proposal is invalidated rather than partially applied.

Normal Mediarama boundaries perform the mutation:

- Smart proposals create ordinary private Smart Collections;
- Manual/review-bucket proposals create ordinary private Manual Collections;
- tag proposals use normalized tag membership;
- title/description and cover proposals require requester ownership.

Acceptance never publishes a Collection, changes an ACL or grants MediaAsset
visibility. Public presentation edits that are already allowed advance the
truthful public-update timeline. A cover selected for an already-public Smart
Collection must independently satisfy the normal public image boundary.

Rejection is idempotent and non-mutating. It is permitted only while the run is
actually ready for review.

Smart Collections remain dynamic after acceptance. The reviewed affected set
describes the analysis scope at review time; the accepted Smart rule may later
match additional owned MediaAssets as ordinary library metadata changes.

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

Provider-specific production adapters can be added behind the provider-neutral
capability/preflight contract without changing Mediarama's proposal, browser
approval or review domain.

The first #16 organization-assistant milestone is complete at the generic
product layer: deterministic analysis, optional provider execution and human
review all converge on the same Mediarama proposal/application model. Vendor
adapters, embeddings and richer batch UX are follow-on integrations rather than
reasons to weaken the proposal-first boundary. Hybrid Collection pins/exclusions
remain #18.
