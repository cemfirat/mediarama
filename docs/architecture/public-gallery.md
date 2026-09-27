# Public Gallery

Status: foundation implementation

## Effective public visibility

Mediarama has one PostgreSQL-level public-visibility boundary: the `effective_public_collections` view.

A collection appears in that view only when:

- it is not deleted;
- `visibility = public`;
- it is not password-protected;
- it has no unresolved migrated-password reset requirement;
- every ancestor collection satisfies the same conditions.

A child therefore cannot become public through its own flag while a parent remains private, restricted, authenticated-only or password-protected.

Public gallery reads and public media search must use this same boundary rather than copying slightly different visibility rules.

## Published media

Public collection pages only return media that are:

- not deleted;
- processing state `ready`;
- moderation state `published`;
- members of an effectively public collection.

## Derivatives

Public delivery exposes generated image derivatives only.

URL shape:

`/media/{media-id}/derivatives/v{processing-version}/{profile}`

Allowed public profiles are currently `thumbnail`, `preview` and `large`.

Before streaming a derivative, Mediarama rechecks that the media is still reachable through an effectively public collection and still published/ready. The immutable original has no public route.

Derivative URLs carry a processing version and may therefore use long-lived immutable caching.

## Privacy boundary

Public presentation reads deliberate Mediarama fields. It must not dump embedded EXIF/IPTC/XMP or storage paths into HTML/API responses.

The separate public-search read model applies an even smaller output contract; rich internal metadata search remains an internal capability.

## Verification

CI exercises public root and collection rendering, derivative delivery, ancestor/password visibility and moderation-state blocking. HTTP smoke failures print the application server log before failing.

## Next

- cursor pagination for large public collections;
- public media detail route with an explicit metadata publication policy;
- video poster/rendition delivery;
- configurable collection covers;
- password access flow with modern password hashing.
