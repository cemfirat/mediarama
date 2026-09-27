# Mediarama Target Architecture

Status: **baseline architecture**
Date: 2026-09-25

This document translates the Coppermine research into a concrete Mediarama application shape. Detailed choices live in `docs/adr/`.

## Architectural position

Mediarama is a clean implementation, not a long-lived Coppermine fork.

The architecture is a **modular monolith first**.

That means one deployable application with strong internal module boundaries, plus background workers. It deliberately avoids premature microservices while keeping media processing and storage replaceable.

```text
┌───────────────────────────────────────────────────────┐
│                    HTTP / CLI                         │
│ public routes | admin routes | API | commands         │
└──────────────────────────┬────────────────────────────┘
                           │
┌──────────────────────────▼────────────────────────────┐
│                    Application                        │
│ commands | queries | use cases | authorization        │
└──────────────────────────┬────────────────────────────┘
                           │
┌──────────────────────────▼────────────────────────────┐
│                       Domain                          │
│ media | collections | identity | interaction          │
└──────────────────────────┬────────────────────────────┘
                           │
┌──────────────────────────▼────────────────────────────┐
│                   Infrastructure                      │
│ PostgreSQL | storage | queue | media tools | search   │
└───────────────────────────────────────────────────────┘

Presentation is fed by view models from HTTP/Application:

┌───────────────────────────────────────────────────────┐
│                    Presentation                       │
│ templates | UIkit components | themes                 │
└───────────────────────────────────────────────────────┘
```

## Why a modular monolith

Mediarama needs substantial functionality but does not initially need distributed-system complexity.

A modular monolith gives us:

- one installation/deployment story;
- ordinary database transactions;
- simpler local development;
- straightforward self-hosting;
- clear code ownership boundaries;
- the option to extract expensive services later if real load requires it.

Do not create separate network services for users, albums, comments, etc. merely because the domain is modular.

Background workers are different: media processing is naturally asynchronous and may scale independently.

## Proposed modules

### Media

Owns:

- MediaAsset
- MediaDerivative
- MediaMetadata
- ingestion state
- media inspection
- derivative definitions
- processing jobs

### Collections

Owns:

- Collection
- CollectionMedia
- ordering
- optional hierarchy
- collection visibility/policies

A media asset can belong to zero, one or many collections.

### Taxonomy

Owns:

- Tag
- MediaTag
- optional CollectionTag

Do not store tags as comma-separated strings.

### Identity & Access

Owns:

- User
- Group
- UserGroup
- Permission
- GroupPermission
- authentication identities
- authorization policies

### Interaction

Owns:

- Comment
- Rating
- Favorite

These features should be modular/configurable rather than assumptions baked into MediaAsset.

### Import

Owns:

- Coppermine source connection
- schema/version detection
- migration mapping
- migration progress
- import warnings/errors
- id mapping
- resumability

### Platform

Owns:

- settings
- deployment-profile/setup defaults;
- site-level public-publishing capability;
- site-level search-index default;
- extension registry
- audit events
- diagnostics
- job monitoring

Deployment-profile labels are operator/setup intent only. They do not replace authentication, Collection authorization or network controls. ADR-0015 defines the separation between deployment exposure, access, publication and search indexing.

## Database

Primary database: **PostgreSQL**.

Key principles:

- UUID/ULID-style public-safe identifiers should be evaluated during schema implementation;
- enforce stable relationships with foreign keys;
- use join tables for many-to-many relations;
- use JSONB for extensible metadata, not for relationships that belong in relational tables;
- keep frequently queried metadata normalized/indexed;
- use explicit timestamps;
- schema changes only through migrations;
- do not expose persistence records directly as view models.

## Storage

Storage interface:

```text
MediaStorage
├── LocalFilesystemStorage
└── S3CompatibleStorage
```

Logical object classes:

```text
originals/
derivatives/
temporary/
imports/
```

Actual key naming is an implementation detail and must not encode user-visible titles.

Original media is immutable by default.

## Ingestion

```text
Client / Importer
      ↓
UploadSession / Import acquisition
      ↓
Temporary storage
      ↓
Finalize
      ↓
Content validation + checksum
      ↓
Original storage
      ↓
MediaAsset(processing)
      ↓
Queue
 ┌────┼───────────────┐
 ↓    ↓               ↓
meta  derivatives     video/audio probe
 │    │               │
 └────┴───────┬───────┘
              ↓
            ready
              ↓
 moderation/publication policy
```

Browser uploads, filesystem imports and Coppermine imports should converge on the same pipeline after acquisition.

## Queue

The application requires a queue abstraction.

Initial jobs include:

- inspect media;
- extract metadata;
- generate image derivative;
- generate video poster;
- transcode video when enabled;
- apply derivative watermark;
- update search index;
- cleanup abandoned upload;
- cleanup orphaned storage object;
- import Coppermine batch.

The exact queue backend belongs to the framework/tooling decision. The domain must not depend on a specific broker.

## Search

Do **not** introduce Elasticsearch/OpenSearch in the foundation.

Start with PostgreSQL-backed search for:

- title;
- description;
- tags;
- selected metadata;
- collection fields.

Only introduce a dedicated search engine if measured product requirements exceed PostgreSQL capabilities.

This avoids unnecessary operational complexity.

## Image processing

Requirements:

- robust content inspection;
- EXIF/IPTC/XMP extraction where available;
- orientation normalization for derivatives;
- configurable thumbnail/preview profiles;
- modern web formats where beneficial;
- processing-version tracking;
- regeneration;
- resource/pixel limits.

The original is not rewritten merely to generate a display representation.

## Video

Video is first-class.

Model at least:

- duration;
- dimensions;
- container;
- video/audio codec metadata;
- poster derivative;
- source/original;
- processing state.

FFmpeg/FFprobe is the expected infrastructure family, but the application layer should use an adapter.

Transcoding profiles should be configurable and can be expanded after the first usable image-gallery release.

## Authorization

Authorization is capability/policy based.

Examples:

```text
media.view
media.upload
media.edit
media.delete
collection.create
collection.media.add
collection.manage
comment.create
comment.moderate
moderation.approve
system.manage
```

A Collection can apply resource-level policy to users/groups.

Authorization is evaluated before producing a view model or executing a command. Themes cannot override it.

## Presentation

Bundled UI: server-rendered HTML with UIkit.

This is intentional:

- a gallery benefits from fast initial rendering and crawlable public pages;
- UIkit already provides the interaction primitives we need;
- a large SPA framework is unnecessary for the first architecture;
- interactive areas such as upload queues can use focused JavaScript.

A JSON API can exist for asynchronous UI operations and future clients without making the whole application headless.

## Theme boundary

A theme owns:

- templates;
- component templates;
- UIkit/LESS variables;
- CSS;
- presentational JavaScript;
- static assets.

A theme does not own:

- database queries;
- authorization;
- domain mutation;
- storage;
- ingestion.

## Extension boundary

Mediarama starts with internal modules and typed events.

Third-party installable plugins are **not** part of the foundation milestone.

First stabilize:

- domain events;
- service interfaces;
- UI slots;
- migrations;
- security/capability model.

Then define a public extension contract.

## Coppermine migration

The importer must transform rather than clone.

Examples:

```text
pictures           → MediaAsset
albums             → Collection
pictures.aid       → CollectionMedia
keywords/dict      → Tag + MediaTag
user_group_list    → UserGroup
favpics serialized → Favorite rows
exif serialized    → JSONB + normalized metadata
visibility integer → explicit access policy
filepath/filename  → storage key + original filename
```

Migration should be resumable and produce a report of transformed, skipped and ambiguous records.

## Repository shape

The exact framework syntax is pending, but the repository should converge toward a structure conceptually like:

```text
src/
  Media/
    Domain/
    Application/
    Infrastructure/
  Collection/
    Domain/
    Application/
    Infrastructure/
  Identity/
  Interaction/
  Import/
  Platform/
  Http/

templates/
  components/
  public/
  admin/

assets/
  less/
  js/

migrations/
tests/
  Unit/
  Integration/
  Functional/

docs/
  adr/
  research/
```

Avoid a directory structure organized only by technical type across the whole project (`Controllers/`, `Models/`, `Services/` with hundreds of unrelated classes).

## Testing baseline

Required categories:

- unit tests for domain rules;
- integration tests for PostgreSQL repositories;
- functional HTTP tests;
- authorization tests;
- upload security tests;
- media processing fixture tests;
- migration tests against representative Coppermine databases;
- storage contract tests shared by local and S3 adapters.

## Operational baseline

The first production-capable architecture should support:

- application process;
- worker process;
- PostgreSQL;
- local storage **or** S3-compatible storage;
- scheduled cleanup/maintenance command;
- structured logs;
- health endpoint;
- queue health/failed-job visibility.

Redis or another queue service should only become mandatory if the chosen queue implementation genuinely requires it.

## Decisions deliberately deferred

Still to decide:

- PHP framework and exact PHP baseline;
- ORM/data mapper;
- queue implementation;
- identifier format;
- exact authentication implementation;
- whether Collections are hierarchical;
- final image library;
- exact video profiles;
- public extension packaging;
- API versioning strategy.

These are implementation decisions, not reasons to delay the already-supported architectural boundaries.
