# ADR-0013: Superseded derivative retention and retryable cleanup

Status: **Accepted**  
Date: 2026-09-27

## Context

Mediarama image regeneration deliberately creates a new processing version instead of overwriting an existing derivative generation.

Public derivative URLs include the processing version and are served with:

`Cache-Control: public, max-age=31536000, immutable`

This gives stable cache identities, but repeated regeneration can otherwise accumulate derivative storage indefinitely.

Deleting old storage objects directly while relational records still exist would create broken database references. Deleting relational rows only after a physical delete would make a storage outage block application-state cleanup and would make retry behavior difficult to reason about.

The storage contract already requires relational state to change first and physical deletion to be retryable operational work after the transaction commits.

## Decision

### Retention

The standard cleanup policy is:

- always retain the newest **three** processing generations per media/kind;
- once a generation falls outside those newest three, start a **400-day cleanup grace period**;
- remove it only after that grace period has elapsed;
- the current/latest generation therefore cannot be selected for cleanup;
- cleanup is generation-aware: all profiles belonging to an eligible processing version are selected together.

The grace clock starts only when enough newer generations exist to push the old generation outside the retained set. It is not based on the old generation's original creation date. The public gallery resolves the newest processing version, so old versions stop being emitted before this cleanup grace can begin. The 400-day default intentionally exceeds the current one-year immutable browser-cache lifetime and adds a safety margin beyond `max-age=31536000`.

The defaults are operational policy, not hard-coded persistence semantics. The maintenance command exposes explicit retention/count options for controlled deployments, but its default invocation is preview-only.

### Mutation boundary

`mediarama:media:cleanup-derivatives` performs no mutation unless `--execute` is supplied.

When execution is requested:

1. eligible derivative rows are selected by media/kind/generation;
2. every selected storage key is checked against its deterministic Mediarama-owned derivative prefix;
3. storage cleanup jobs are inserted;
4. the selected `media_derivatives` rows are removed in the **same database transaction**;
5. only after that transaction commits are physical storage objects deleted.

A database rollback therefore leaves both derivative rows and storage unchanged from the application's point of view.

### Retryable storage debt

Physical deletion is tracked in `storage_cleanup_jobs`.

A cleanup job is complete only after the object is absent from storage. A missing object is considered an idempotent success.

Workers claim jobs with a short lease. A process that dies after claiming a job leaves a stale claim that a later run can reclaim. A process that dies after physical deletion but before acknowledgement leaves a job whose retry observes the already-missing object and completes safely.

Failed deletes return the job to `pending`; the application does not recreate a removed derivative row merely because storage cleanup failed.

### Failed regeneration orphans

Regeneration failure cleanup persists a retryable cleanup job **before** attempting to delete a derivative object produced by a failed generation.

If immediate deletion succeeds, that cleanup job is acknowledged. If storage deletion fails or the process terminates after the job is recorded, the normal cleanup runner can reconcile the orphan later.

### Ownership boundary

Cleanup never scans or blindly deletes arbitrary bucket/filesystem contents.

Superseded rows must resolve under:

`derivatives/{media-id}/v{processing-version}/...`

Unexpected keys abort the database transaction. This keeps derivative maintenance inside the namespace Mediarama deterministically owns.

### No manual generation pin in the initial model

The current product does not add a manual “pin this old derivative generation forever” state.

The combination of a 400-day grace period **after falling outside the newest-three set** and three retained generations is the current public-cache safety contract. If a future product requirement treats derivative URLs as permanent archival references beyond that boundary, a first-class pin/reference model must be designed before shortening or bypassing retention.

Original media remains unaffected and immutable.

## Consequences

Positive:

- repeated regeneration no longer implies unbounded derivative growth;
- the latest three generations are always retained;
- immutable public URLs have more than one cache lifetime before eligibility;
- relational deletion and physical deletion cannot silently diverge because storage debt is durable;
- interrupted cleanup and failed storage operations are retryable;
- failed-generation objects have a reconciliation path;
- no shared storage prefix is blindly scanned.

Trade-offs:

- cleanup requires a small operational queue table;
- physical storage can temporarily exceed relational state while retryable deletion debt exists;
- deployments that intentionally need old derivative URLs beyond the retention contract must choose a different retention value or introduce an explicit reference/pinning model;
- object-store implementations must preserve the same `MediaStorage` delete/idempotency contract.

## Verification

The normal CI suite covers:

- dry-run preview behavior;
- generation-aware retention;
- interrupted cleanup resumed from persisted storage debt;
- repeated/idempotent execution;
- preservation of the latest three versions;
- rejection and rollback for storage keys outside the owned derivative prefix;
- failed regeneration leaving retryable orphan cleanup debt.
