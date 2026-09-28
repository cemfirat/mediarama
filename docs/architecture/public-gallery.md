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

## Public MediaAsset identity

Public MediaAssets have one stable membership-independent HTML identity:

`GET /media/{media-id}`

The UUID is the initial public identity. A MediaAsset can belong to many
Collections, so its canonical page is never derived from one Collection path.

The page is available only when the same effective public boundary used by
derivative delivery succeeds:

- site-level public publishing is enabled;
- the MediaAsset is not deleted;
- processing state is `ready`;
- moderation state is `published`;
- at least one membership reaches an `effective_public_collections` row.

A second private or restricted membership does not make that membership public
and is not exposed by the detail read model. Conversely, an inaccessible
ancestor on the only otherwise-public membership makes the MediaAsset page
unavailable.

The detail controller receives a dedicated public DTO. It contains public
title/description, media presentation type, dimensions/duration where useful,
current public image derivative identities and the resolved MediaAsset
search-index state. It does not expose source filenames, storage identities,
raw EXIF/IPTC/XMP, GPS, provenance bags or Collection membership data.

Collection pages may link to this stable MediaAsset identity while image clicks
can continue to use lightbox derivatives.

## Derivatives

Public delivery exposes generated presentation derivatives only. The immutable
original has no public route.

Image URL shape:

`/media/{media-id}/derivatives/v{processing-version}/{profile}`

Allowed public image profiles are currently `thumbnail`, `preview` and
`large`.

Video presentation URL shape:

`/media/{media-id}/video/v{processing-version}/{profile}`

The first video generation contains a complete pair:

- `poster` — bounded JPEG poster frame;
- `browser_mp4` — bounded H.264/AAC MP4 presentation rendition.

The public MediaAsset page renders the MP4 through a normal HTML `<video
controls>` element with the generated poster. It does not autoplay and it does
not fall back to the immutable original when a presentation derivative is
missing.

Before streaming any derivative, Mediarama rechecks that the media is still
reachable through an effectively public collection and still published/ready.

Versioned playback supports single HTTP byte ranges so browsers can seek without
turning the source/original into a public download contract. Multiple ranges are
deliberately not implemented in the first version.

Derivative URLs carry a processing version and therefore use long-lived
immutable caching. MediaAsset `noindex` remains an independent HTTP response
policy on derivative delivery.

## Search-engine discovery

Access/publication and search indexing are separate concerns.

A resource can be publicly reachable while carrying an effective `noindex` policy. Conversely, an `index` preference never overrides private/restricted access, unpublished moderation state or disabled site-level public publishing.

Collection pages and MediaAsset pages own their index preference independently. MediaAsset SEO does not inherit from Collection membership because one asset may belong to multiple Collections.

Implementation details live in `docs/architecture/publication-indexing.md` and ADR-0015.

## Privacy boundary

Public presentation reads deliberate Mediarama fields. It must not dump embedded EXIF/IPTC/XMP or storage paths into HTML/API responses.

The separate public-search read model applies an even smaller output contract; rich internal metadata search remains an internal capability.

## Verification

CI exercises public root and collection rendering, the stable MediaAsset detail
route, image derivative delivery, video processing, poster/playback generation,
byte-range playback, multi-membership privacy, ancestor/password visibility,
processing/moderation safety gates and search-index behavior. HTTP smoke
failures print the application server log before failing.

## Next

- cursor pagination for large public collections;
- configurable collection covers;
- password access flow with modern password hashing.
