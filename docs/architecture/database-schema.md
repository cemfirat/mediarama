# Initial PostgreSQL Schema

Status: **foundation design**
Date: 2026-09-25

This is the first normalized persistence model for Mediarama. It is intentionally designed from the target domain rather than copied from Coppermine.

## Identifier policy

Use UUIDv7 identifiers for aggregate/entity primary keys where application-generated IDs are useful.

Reasons:

- sortable by creation time;
- safe to expose in URLs/APIs;
- no cross-import sequence collisions;
- suitable for distributed/background creation.

PostgreSQL-native/internal tables may use integer identities where public identity is irrelevant.

## Core entity relationship model

```mermaid
erDiagram
  USER ||--o{ USER_GROUP : belongs
  GROUP ||--o{ USER_GROUP : contains
  GROUP ||--o{ GROUP_PERMISSION : grants
  PERMISSION ||--o{ GROUP_PERMISSION : assigned

  USER ||--o{ MEDIA_ASSET : owns
  MEDIA_ASSET ||--o{ MEDIA_DERIVATIVE : produces
  MEDIA_ASSET ||--o{ COLLECTION_MEDIA : appears_in
  COLLECTION ||--o{ COLLECTION_MEDIA : contains
  USER ||--o{ COLLECTION : owns

  MEDIA_ASSET ||--o{ MEDIA_TAG : tagged
  TAG ||--o{ MEDIA_TAG : classifies

  USER ||--o{ FAVORITE : creates
  MEDIA_ASSET ||--o{ FAVORITE : favorited

  MEDIA_ASSET ||--o{ COMMENT : receives
  USER ||--o{ COMMENT : authors

  MEDIA_ASSET ||--o{ RATING : receives
  USER ||--o{ RATING : gives

  COLLECTION ||--o{ COLLECTION_ACCESS : protected_by
  USER ||--o{ COLLECTION_ACCESS : user_principal
  GROUP ||--o{ COLLECTION_ACCESS : group_principal
```

## users

Core fields:

- `id uuid primary key`
- `username varchar(...) unique`
- `email citext unique nullable`
- `password_hash text nullable`
- `display_name text nullable`
- `status varchar`
- `locale varchar nullable`
- `created_at timestamptz`
- `updated_at timestamptz`
- `last_login_at timestamptz nullable`

External/bridged identity support should use a separate identity table rather than overloading the user row.

## groups

- `id uuid primary key`
- `slug varchar unique`
- `name text`
- `is_system boolean`
- timestamps

## user_groups

- `user_id uuid fk users`
- `group_id uuid fk groups`
- `is_primary boolean default false`
- `created_at`
- primary key `(user_id, group_id)`

Enforce at most one primary group per user with a partial unique index.

## permissions / group_permissions

Permissions use stable string keys such as `media.upload`.

`permissions`:

- `key varchar primary key`
- `description text`

`group_permissions`:

- `group_id uuid fk`
- `permission_key varchar fk`
- primary key pair

## media_assets

- `id uuid primary key`
- `owner_id uuid fk users nullable`
- `storage_disk varchar`
- `storage_key text`
- `original_filename text`
- `mime_type varchar`
- `media_type varchar`
- `byte_size bigint`
- `checksum_sha256 char(64)`
- `width integer nullable`
- `height integer nullable`
- `duration_ms bigint nullable`
- `title text nullable`
- `description text nullable`
- `captured_at timestamptz nullable`
- `processing_state varchar`
- `moderation_state varchar`
- `search_index_policy varchar` (`inherit | index | noindex`)
- `public_published_at timestamptz nullable`
- `public_updated_at timestamptz nullable`
- `public_published_origin varchar nullable` (`editorial | imported`)
- `public_published_source varchar nullable` (required only for imported publication dates)
- `metadata jsonb not null default '{}'`
- `created_at timestamptz`
- `updated_at timestamptz`
- `deleted_at timestamptz nullable`

Constraints:

- byte_size >= 0
- width/height > 0 when present
- duration >= 0 when present
- unique `(storage_disk, storage_key)`

Do not put a collection/album ID on this table.

## media_derivatives

- `id uuid primary key`
- `media_id uuid fk media_assets on delete cascade`
- `kind varchar`
- `profile varchar`
- `processing_version integer`
- `storage_disk varchar`
- `storage_key text`
- `mime_type varchar`
- `byte_size bigint`
- `width integer nullable`
- `height integer nullable`
- `duration_ms bigint nullable`
- `metadata jsonb`
- timestamps

Unique logical derivative:

`(media_id, kind, profile, processing_version)`

## derivative_cleanup_jobs

Durable queue for physical derivative-object deletion after relational
retirement or a failed-generation cleanup attempt.

- `id bigint identity primary key`
- `storage_disk varchar`
- `storage_key text`
- `reason varchar`
- `media_id uuid nullable`
- `kind varchar nullable`
- `profile varchar nullable`
- `processing_version integer nullable`
- timestamps

Unique storage identity:

`(storage_disk, storage_key)`

This table intentionally does not foreign-key `media_id` or a
`media_derivatives` row. Cleanup work must survive deletion of the relational
entity that made the storage object obsolete.

Retention staging deletes an eligible complete processing generation from
`media_derivatives` and inserts the corresponding cleanup jobs in the **same
PostgreSQL transaction**. Physical storage deletion happens only afterward and
is retryable/idempotent.

## collections

- `id uuid primary key`
- `owner_id uuid fk users nullable`
- `parent_id uuid fk collections nullable` (reserved; hierarchy behavior must be validated before UI reliance)
- `slug varchar nullable`
- `title text`
- `description text nullable`
- `visibility varchar`
- `mode varchar` (`manual | smart`), default `manual`
- `smart_rule jsonb nullable` (required only when mode is `smart`)
- `search_index_policy varchar` (`inherit | index | noindex`)
- `public_published_at timestamptz nullable`
- `public_updated_at timestamptz nullable`
- `public_published_origin varchar nullable` (`editorial | imported`)
- `public_published_source varchar nullable` (required only for imported publication dates)
- `cover_media_id uuid fk media_assets nullable`
- `position integer default 0`
- timestamps
- `deleted_at nullable`

A collection is not a storage directory.

Manual Collections persist membership in `collection_media`. Smart Collections persist a versioned validated Mediarama rule and resolve membership dynamically; their result rows are not copied into `collection_media`. Existing and Coppermine-imported Collections remain manual.

Smart v1 additionally requires a non-null owner and `visibility = private`. The database rejects public Smart rows and rejects `collection_media` inserts/retargets to Smart Collections. Existing upload/add authorization also treats Smart Collections as non-manual destinations.

Public publication timestamps are intentionally separate from ordinary creation/update timestamps. Existing rows are not backfilled from `created_at`; see `docs/architecture/publication-timeline.md`.

## collection_media

- `collection_id uuid fk collections on delete cascade`
- `media_id uuid fk media_assets on delete cascade`
- `position integer`
- `added_by uuid fk users nullable`
- `created_at`
- primary key pair

Index `(collection_id, position)`.

This is the structural change that permits one media asset in multiple collections without duplication.

## collection_access

Explicit resource-level policy.

- `id uuid primary key`
- `collection_id uuid fk`
- exactly one of `user_id` / `group_id`
- `capability varchar`
- `effect varchar` (`allow` initially; deny rules only if proven necessary)
- timestamps

Check constraint: exactly one principal column is non-null.

## platform_settings

Singleton site-level publication/discovery settings.

- `id smallint primary key`, constrained to `1`
- `deployment_profile varchar`
- `public_publishing_enabled boolean`
- `search_index_default varchar` (`index | noindex`)
- `setup_status varchar` (`pending | completed`)
- `setup_completed_at timestamptz nullable`
- `setup_completed_by uuid nullable fk users on delete set null`
- `setup_completed_via varchar nullable` (`migration | browser | cli | existing_admin`)
- timestamps

`deployment_profile` records the last applied setup/operator preset. It is not an ACL and does not prove actual network isolation.

The singleton setup row is also the concurrency boundary for first-run administrator creation. Bootstrap takes a PostgreSQL row lock before creating/recovering an administrator and completing setup, preventing concurrent requests from creating two initial administrators.

For new empty installations the migration initializes **Private workspace** semantics:

- public publishing off;
- site search-index default `noindex`.

When upgrading an existing database that already has effectively public Collections, the migration preserves that established behavior by initializing **Public publishing** with `index`.

Resource policies stay separate:

- Collection policy controls the Collection page;
- MediaAsset policy controls the MediaAsset/public media identity;
- neither policy can bypass access/publication gates.

## tags

- `id uuid primary key`
- `slug varchar unique`
- `name text`
- timestamps

## media_tags

- `media_id uuid fk media_assets on delete cascade`
- `tag_id uuid fk tags on delete cascade`
- `source varchar` (manual/imported/embedded)
- primary key pair

## favorites

- `user_id uuid fk users on delete cascade`
- `media_id uuid fk media_assets on delete cascade`
- `created_at`
- primary key pair

## comments

- `id uuid primary key`
- `media_id uuid fk media_assets on delete cascade`
- `user_id uuid fk users nullable`
- `guest_name text nullable`
- `body text`
- `moderation_state varchar`
- timestamps
- `deleted_at nullable`

Do not make IP retention a required comment-domain field. Security/audit retention belongs to a separate policy.

## ratings

- `user_id uuid fk users on delete cascade`
- `media_id uuid fk media_assets on delete cascade`
- `value smallint`
- `created_at`
- `updated_at`
- primary key pair
- check value within configured v1 range

Aggregates are derived/cached, not the source of truth.

## upload_sessions

- `id uuid primary key`
- `user_id uuid fk users`
- `target_collection_id uuid fk collections nullable`
- `original_filename text`
- `expected_size bigint`
- `expected_mime varchar nullable`
- `temporary_storage_key text`
- `status varchar`
- `expires_at timestamptz`
- `last_failure_code varchar nullable`
- `last_failure_stage varchar nullable`
- `last_failure_retryable boolean nullable`
- `last_failed_at timestamptz nullable`
- `created_at`
- `updated_at`

Failure metadata is all-null or all-present. It contains only stable sanitized application state, never arbitrary exception text, SQL, paths or stack traces.

Parts need not be rows if the selected storage multipart mechanism owns part state. A DB table for parts should only be added if the implementation needs it.

## upload quota accounting

Committed quota usage is derived from owned immutable originals:

`SUM(media_assets.byte_size WHERE owner_id = :user)`

Soft-deleted media continue to count until the MediaAsset/storage object is physically purged. Generated derivatives are system-managed overhead and are not charged to upload quota.

`upload_quota_reservations`:

- `upload_session_id uuid primary key fk upload_sessions on delete cascade`
- `user_id uuid fk users`
- `reserved_bytes bigint >= 0`
- `created_at timestamptz`

The UploadSession FK is `DEFERRABLE INITIALLY DEFERRED` so reservation and session creation can share one transaction while the reservation row is inserted first.

Policy tables:

- `user_storage_quotas(user_id primary key, limit_bytes, updated_at)`
- `group_storage_quotas(group_id primary key, limit_bytes, updated_at)`

`limit_bytes = 0` means explicitly unlimited. Missing rows mean no override. User policy wins over group policy; otherwise any unlimited group makes quota unlimited, and the largest finite group limit wins. If no policy applies, `UPLOAD_DEFAULT_QUOTA_BYTES` is used.

Reservation creation serializes on the existing user row with `SELECT ... FOR UPDATE`. Committed and reserved usage are read in one PostgreSQL statement/snapshot. Finalization persists the MediaAsset and deletes the reservation in the same database transaction, avoiding a duplicate mutable committed counter.

## import_runs

- `id uuid primary key`
- `source_type varchar`
- `source_version varchar nullable`
- `status varchar`
- `options jsonb`
- `progress jsonb`
- `started_at nullable`
- `completed_at nullable`
- timestamps

## import_id_map

- `import_run_id uuid fk import_runs`
- `entity_type varchar`
- `source_id varchar`
- `target_id uuid`
- primary key `(import_run_id, entity_type, source_id)`

This makes Coppermine import resumable and auditable.

## Smart Collection rule JSON

Smart Collection rules are structured application-owned JSON, not arbitrary
SQL and not raw metadata query paths. V1 rules are validated against a fixed
allowlist of normalized fields/operators, bounded for depth/predicate/list
complexity and compiled only to fixed SQL fragments with bound values.

Exact GPS (`latitude`/`longitude`) and arbitrary
`metadata`/`metadata_provenance` paths are intentionally outside the V1
rule grammar.

## JSONB policy

JSONB is appropriate for:

- raw/extended EXIF/IPTC/XMP metadata;
- codec/probe details;
- importer options/progress;
- derivative processor metadata.

JSONB is **not** appropriate for:

- tags;
- collection membership;
- favorites;
- group membership;
- permissions;
- comments/ratings.

Relationships remain relational.

## Search indexes

Initial search should use PostgreSQL.

Candidate generated/indexed search vector from:

- media title;
- description;
- tag names;
- collection title/description.

Do not create an external search service in v1.

## Deletion policy

Default strategy:

- user-facing MediaAsset/Collection/Comment deletion: soft delete first;
- pure joins/derivatives: cascade where safe;
- original storage deletion: asynchronous cleanup after database state commits;
- user deletion: preserve media attribution via nullable owner where required;
- import records: retain for audit/reconciliation.

Storage deletion is not part of the SQL transaction; it requires retryable cleanup jobs.

## Coppermine mapping validation

This model directly resolves researched legacy patterns:

| Coppermine | Mediarama |
| --- | --- |
| pictures.aid | collection_media |
| pictures.filepath + filename | storage_disk + storage_key + original_filename |
| pictures.user1..4 | metadata/custom-field strategy |
| pictures.keywords + dict | tags + media_tags |
| albums.visibility | collection.visibility + collection_access |
| users.user_group_list | user_groups |
| favpics serialized | favorites |
| exif.exifData serialized | media_assets.metadata |
| votes + aggregate fields | ratings + derived aggregates |
| categories/categorymap | collection hierarchy/policy, migration rule pending |

## Open schema questions

These do not block the foundation:

- whether collection hierarchy is exposed in v1;
- exact custom-field subsystem beyond embedded metadata;
- guest comments in first public release;
- rating scale;
- external identity table shape;
- audit-event retention.

They should not be guessed into the first migration before their features are implemented.
