# ADR-0017: Organization intelligence is proposal-first and provider-neutral

- Status: Accepted
- Date: 2026-09-28
- Tracks: #16, #133
- Related: ADR-0008, ADR-0009

## Context

Mediarama's deterministic organization layer is now capable of normalized
Library filtering, Smart Collection rules and deliberate public publication.

The next product layer may use deterministic heuristics, external AI providers
or local models to suggest useful organization for large media libraries.

Allowing a provider response to mutate Collections, tags or publication state
directly would create a second, difficult-to-audit domain model and would make
privacy, authorization and rollback behavior provider-dependent.

Mediarama therefore needs a provider-independent review boundary before visual
AI, embeddings or vendor-specific integration is introduced.

## Decision

Organization intelligence is proposal-first.

An analysis run snapshots the authorized MediaAssets selected by the requesting
user. Producers may inspect that scope and create versioned Mediarama-owned
proposals, but proposal generation does not mutate normal library organization.

Every proposal contains:

- a fixed Mediarama proposal type;
- a validated versioned payload;
- a human-readable rationale;
- an explicit affected-MediaAsset set drawn from the run scope;
- one or more evidence summaries;
- evidence source classification as deterministic metadata or inference.

Provider/model/version, when applicable, is audit identity for the run. It is
not part of proposal identity and raw provider requests/responses are not stored
as Mediarama domain state.

## Producer boundary

The foundation recognizes three producer kinds:

- `metadata` — deterministic Mediarama logic with no AI provider identity;
- `ai_external` — an explicitly configured external provider;
- `ai_local` — an explicitly configured local/on-prem provider.

A metadata producer cannot carry provider/model fields.

AI producers require a provider and model identity; an optional model version
may also be recorded.

The provider adapter and external-send preflight are separate work in #135.
This ADR does not authorize an external network request.

## Proposal types

The first application-owned payload vocabulary covers:

- Smart Collection suggestion;
- Manual Collection suggestion;
- tag suggestion;
- review bucket;
- title/description suggestion;
- cover suggestion.

Payloads use exact key allowlists and version 1 validation. Smart Collection
suggestions validate their rule through the ordinary Smart Collection rule
grammar. Arbitrary raw metadata fields and exact GPS keys are therefore not an
extension mechanism.

Hybrid pins/exclusions remain outside this foundation.

## Scope and authorization

A run may contain only MediaAssets that satisfy the existing authenticated
MediaAsset visibility boundary for the requester at run creation time.

The selected MediaAsset IDs are persisted relationally as the analysis scope.
Provider-specific scope JSON is not persisted.

Every proposal MediaAsset must be a member of the same run scope. Database
foreign keys reinforce this invariant.

A later acceptance action must revalidate mutation permissions and stale state;
view permission at analysis time is not itself permission to edit a shared
MediaAsset or Collection.

## Review lifecycle

Runs start in `draft` while producers add proposals. A run can move to
`ready_for_review` only after at least one proposal exists.

Proposals start in `pending_review`.

The foundation supports explicit rejection. Rejection changes proposal review
state only and is idempotent; it does not alter ordinary Collections, tags,
membership, ACLs or publication state.

Atomic acceptance/edit/reject behavior belongs to #136 and must use ordinary
Mediarama application services rather than writing AI-specific shadow state.

## Privacy

The proposal store deliberately does not copy:

- immutable source paths;
- original filenames;
- raw EXIF/IPTC/XMP snapshots;
- exact latitude/longitude;
- provider credentials;
- arbitrary provider request/response bodies.

Evidence is a bounded human-readable summary, not a raw provider blob.

Future producers remain responsible for constructing privacy-safe summaries;
the provider preflight in #135 must additionally define exactly what leaves the
installation.

## Consequences

Positive:

- deterministic organization works without AI;
- provider changes do not alter the Mediarama proposal model;
- rejected suggestions are demonstrably non-mutating;
- proposal review can be audited without retaining raw provider traffic;
- analysis scope and affected MediaAssets are relational and authorization-safe;
- accepted results can later become normal Collections, Smart Collections or
  tags through existing product boundaries.

Trade-offs:

- providers require adapters instead of directly returning executable actions;
- proposal payload types must be expanded deliberately;
- accepted changes require a separate application workflow and stale-state
  revalidation;
- evidence summaries intentionally contain less forensic detail than storing a
  complete provider response.

## Verification

The implementation must prove:

- proposal payload/version/type validation fails closed;
- exact-GPS/raw-field payload expansion is rejected;
- analysis scopes cannot include inaccessible MediaAssets;
- another user cannot read/review a private run;
- affected MediaAssets cannot escape the run scope;
- metadata-only runs need no AI configuration;
- provider/model/version can be recorded without raw provider state;
- rejection leaves normal library organization unchanged;
- proposal persistence does not acquire source/GPS data from MediaAssets.
