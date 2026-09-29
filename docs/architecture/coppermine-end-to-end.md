# End-to-end Coppermine Migration

The only normal command that writes migrated Coppermine data is:

`php bin/console mediarama:import:coppermine`

Read-only diagnostics remain available:

- `mediarama:import:coppermine:inspect`
- `mediarama:import:coppermine:reconcile`

The former stage-specific write commands are intentionally removed from the normal CLI surface. They could bypass the complete preflight, dependency order and run audit.

## Source identity vs execution runs

Two identities are deliberately separate.

### Source instance

`COPPERMINE_SOURCE_ID` is a stable operator-assigned ID for one Coppermine installation.

It is validated as a short safe identifier and stored internally as:

`coppermine:<source-id>`

Examples:

- `coppermine:customer-a`
- `coppermine:archive-2026`

The same ID must be reused when retrying/resuming the same source gallery. It must never be reused for a different source gallery.

This source key scopes:

- `import_mappings`
- `import_checkpoints`
- reconciliation

Credentials, database host names and filesystem paths are deliberately not used as the identity because they can change during an otherwise legitimate resume.

### Import run

Every invocation of the full migration creates a new `import_runs` row.

A run is an **attempt/audit record**, not the identity of the source. It records:

- source type;
- source key;
- detected source version;
- status;
- current/final stage;
- failure text when applicable;
- timestamps.

A failed run followed by a retry therefore creates two audit rows but reuses the same source-scoped mappings/checkpoints.

## Stage order

The full runner executes:

1. inspect source schema
2. fail-closed preflight
3. groups and users
4. categories and albums
5. pictures/original files
6. explicit collection covers
7. keywords/tags and linked membership
8. visibility/ACL conversion
9. comments/favorites/recoverable ratings
10. reconciliation

Each resumable row-level stage advances its source-scoped checkpoint only after the corresponding target write/mapping is durable.

## Persistence model

`import_mappings`

- primary key: `(source_key, entity_type, source_id)`
- maps one stable source instance's entity ID to one target UUID
- survives failed execution attempts so resume is idempotent

`import_checkpoints`

- primary key: `(source_key, stage)`
- stores the last safe source cursor for that source instance/stage
- survives failed execution attempts

`import_runs`

- one row per execution attempt
- links to the stable source using `source_key`
- does not own the reusable mappings/checkpoints

The earlier unused `import_id_map` table is removed. Keeping both a run-owned map and a source-owned map would create two competing sources of truth.

## Verification

CI covers both required properties:

- **resume:** a 5,004-media source is forced to fail at picture 3500, then a second run with the same source ID resumes from checkpoint 3499 and ends with exactly 5,004 unique mappings/media/jobs;
- **source isolation:** repository integration tests store the same entity/source ID under two different source keys and verify that mappings/checkpoints remain independent.

This model makes retries safe without allowing two independent Coppermine installations to share migration state accidentally.
