# Collection Access Policy

Status: foundation implementation

## Purpose

Collection visibility and resource-scoped capabilities are evaluated through one actor-aware application contract.

Public presentation remains a separate deliberately small boundary backed by `effective_public_collections`.

## View semantics

### Public

Anonymous access uses `effective_public_collections`.

That view requires the complete ancestor chain to be:

- not deleted;
- `public`;
- not password-protected;
- free of unresolved migrated-password reset requirements.

Authenticated actors use the same hierarchy principle.

### Authenticated

Any authenticated actor may view an `authenticated` node only when every ancestor is also viewable by that actor.

### Private

Only the owner may view a private node.

A `collection.view` ACL does not make a private Collection shareable. Deliberate sharing requires switching to `restricted`.

### Restricted

The following may view:

- owner;
- explicitly granted user with `collection.view`;
- member of an explicitly granted group with `collection.view`.

### Password migration

A migrated Collection with `password_protected` or `password_reset_required` remains fail-closed for non-owners until a modern password flow is implemented/resolved.

The owner remains able to manage/view the resource.

## Hierarchy

A child never widens access beyond an inaccessible ancestor.

The DBAL policy walks the complete parent chain. Deleted nodes and hierarchy cycles fail closed.

## Capabilities remain independent

`collection.view` and `collection.media.add` are separate resource capabilities.

A view grant does not grant upload rights.

A media-add grant does not implicitly publish or expose a private Collection.

The upload create/finalize boundary delegates `collection.media.add` to this shared policy.

## Public versus library reads

Public gallery/search must continue using their dedicated public DTO/query boundary.

Future authenticated library search (#34) should consume these same Collection semantics at the query boundary rather than loading inaccessible rows and filtering them afterward.

## Verification

The PostgreSQL integration test covers:

- anonymous effective-public access;
- authenticated visibility;
- owner-only private access;
- restricted user/group grants;
- unrelated denial;
- ancestor restriction;
- fail-closed password migration;
- deleted/cyclic hierarchy denial;
- independence of view and media-add grants.
