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

Site-level public publishing is an additional gate defined by ADR-0015. A Collection being structurally `public` does not force an installation configured as a private workspace to expose anonymous public routes.

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

## Search-engine discovery

Access/publication and search indexing are separate concerns.

A resource can be publicly reachable while carrying an effective `noindex` policy. Conversely, an `index` preference never overrides private/restricted access, unpublished moderation state or disabled site-level public publishing.

Collection pages and MediaAsset pages own their index preference independently. MediaAsset SEO does not inherit from Collection membership because one asset may belong to multiple Collections.

Implementation details live in `docs/architecture/publication-indexing.md` and ADR-0015.

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
