# Resumable Upload Protocol

Status: foundation implementation

## Flow

1. `POST /api/uploads` creates a session.
2. Client splits the asset into chunks.
3. `PUT /api/uploads/{id}/chunks/{index}` uploads each chunk.
4. `GET /api/uploads/{id}` returns accepted chunks so an interrupted client can resume.
5. `POST /api/uploads/{id}/complete` validates continuity and assembles the temporary object.
6. `POST /api/uploads/{id}/finalize` re-authorizes the destination, detects actual content/MIME, computes SHA-256, applies the upload allow policy, structurally validates the media with ImageMagick (images) or FFprobe (audio/video), and only then promotes the immutable original, creates the MediaAsset and dispatches background processing.

## Chunk headers

- `Content-Length`
- `Upload-Offset`
- `Upload-Checksum-SHA256`

Chunk identity is the session UUID plus zero-based chunk index.

## Integrity

Every chunk is SHA-256 verified before acceptance.

Completion verifies:

- contiguous offsets;
- all chunk files exist;
- assembled byte count equals the session's expected asset size.

Finalization then computes the full-file SHA-256 and detects MIME from file content.

## Structural media validation

MIME detection is not treated as decoder validation.

After the MIME/type allow policy and expected-size check pass, Mediarama validates the temporary object before finalization begins:

- images must decode far enough for the hardened ImageMagick geometry inspector to return valid dimensions;
- audio must contain an audio stream recognized by FFprobe;
- video must contain a video stream recognized by FFprobe.

FFprobe runs through an argv-only process with a parent timeout plus bounded probe size and analyze duration. With the current local-storage adapter the validator probes the already assembled temporary file in place; it does not duplicate a potentially multi-gigabyte upload merely to validate it. A structural validation failure leaves the upload session in `uploaded`, keeps the temporary object retryable, and prevents immutable-original promotion, `MediaAsset` creation and background dispatch.


## Finalization idempotency and crash recovery

For media created by the resumable upload pipeline, the MediaAsset UUID is the UploadSession UUID. The immutable original therefore has one deterministic target key for the lifetime of the session.

Finalization uses two short PostgreSQL critical sections backed by `SELECT ... FOR UPDATE` on the upload-session row:

1. after MIME/decoder/probe validation, claim `uploaded -> finalizing`;
2. after filesystem promotion, persist the MediaAsset, finalization mapping, completed session state and processing enqueue exactly once.

ImageMagick/FFprobe work and filesystem promotion remain outside the row lock.

The `finalizing` state is recoverable. A retry uses the temporary object when it still exists, or the deterministic permanent object when a previous request already promoted it. Local promotion is idempotent and re-checks the target after a concurrent rename race.

Before committing, the promoted object's size and SHA-256 must still match the validated content.

The current deployment uses Symfony's Doctrine Messenger transport on the same PostgreSQL connection. CI must verify that queue insertion participates in the final database transaction; a future non-Doctrine transport requires an explicit outbox rather than assuming cross-system atomicity.

## Observable failure state

UploadSession persistence carries structured last-failure metadata:

- stable failure code;
- lifecycle stage;
- retryable flag;
- failure timestamp.

The model deliberately stores no raw exception message or stack trace. Retryable failures do not automatically turn the session into `failed`; terminal failures do. A successful state transition clears stale failure metadata.

Downstream MediaAsset processing has its own `processing_state` and remains a separate failure domain.

## Resume

Clients query session status and only resend missing chunks.

Writing an existing chunk index replaces that chunk only after the replacement passes size/checksum verification.

## Authorization

Destination authorization is checked when the session is created and again at finalization.

Collection owners may upload to their own collection. Resource-scoped `collection.media.add` rules may grant upload capability to explicitly allowed users or groups.

This is the current ACL foundation; richer sharing/access semantics remain separate hardening work.

## Current authentication boundary

Production HTTP requests use Symfony Security with the PostgreSQL `users` table, stateful browser sessions and form login.

- only active identities authenticate normally;
- `CurrentUser` resolves the authenticated Mediarama UUID from the Symfony Security token;
- production upload routes require `ROLE_USER`;
- unsafe session-authenticated upload requests also require the upload CSRF token obtained from `GET /api/auth/csrf`.

`X-Mediarama-User` remains a development/test helper only. Production ignores that caller-supplied header and does not use it as an authentication mechanism.

The complete authentication/session boundary is documented in `docs/architecture/authentication.md`.

## Limits

Default example configuration:

- maximum asset: 2 GiB;
- maximum chunk: 16 MiB.

These are deployment policy values, not hard-coded product limits.

## Persistent quota accounting

Every created UploadSession has a PostgreSQL-backed reservation, even when the effective policy is unlimited.

Effective quota policy is resolved in this order:

1. explicit user quota;
2. otherwise normalized group quota policies — any unlimited group wins, otherwise the largest finite group quota wins;
3. otherwise `UPLOAD_DEFAULT_QUOTA_BYTES`.

A value of `0` is explicit unlimited. The default deployment value is also `0`, so accounting is active without imposing an arbitrary first-release storage cap.

Session creation locks the user row and performs the usage check, reservation insert and UploadSession persistence in one transaction. Committed usage is derived from owned immutable MediaAssets through an indexed `owner_id` lookup; generated derivatives are not charged.

If a finite policy would be exceeded, `POST /api/uploads` returns HTTP `422` with stable error code `upload_quota_exceeded` plus the effective limit, committed bytes, reserved bytes and requested bytes. No UploadSession or reservation is persisted for the rejected request.

Successful finalization persists the MediaAsset and removes the reservation in the same existing finalization transaction. Expired-session deletion releases reservations through the database FK cascade, so cleanup is idempotent after crashes.

Coppermine `group_quota` values are imported from KiB to bytes. The normalized Mediarama policy preserves Coppermine's multi-group rule: any zero/unlimited group makes quota unlimited, otherwise the maximum finite group quota wins.

## Remaining hardening

- observable retry/failure API (#37);
- richer resource-scoped collection sharing/access policy;
- full authenticated HTTP + PostgreSQL + filesystem upload-flow coverage beyond the existing finalization/security integration tests.
