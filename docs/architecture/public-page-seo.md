# Public page SEO boundary

Status: implemented foundation
Date: 2026-09-28

This document defines canonical, social and structured metadata for the public
HTML identities Mediarama currently exposes:

- `GET /collections`
- `GET /collections/{id}`
- `GET /media/{id}`

MediaAsset identity is deliberately independent from Collection membership.
One MediaAsset may belong to many Collections, so a Collection path is not a
canonical media identity.

## Trusted canonical origin

Every canonical and social URL is generated from the configured
`PUBLIC_BASE_URL`.

Request `Host`, forwarded host values and arbitrary browser input are not
canonical-origin inputs. `PublicUrlGenerator` validates that the configured
value is an absolute HTTP(S) origin without path, credentials, query or
fragment, then combines it with application-generated absolute paths.

This is the same trust boundary used by sitemap discovery.

## Access before discovery

Canonical metadata never grants access.

Collection pages still have to pass the normal public Collection boundary.
MediaAsset pages additionally require:

- site public publishing enabled;
- MediaAsset not deleted;
- processing state `ready`;
- moderation state `published`;
- at least one membership in an effectively public Collection.

A MediaAsset may simultaneously have private memberships. Those memberships are
not exposed merely because a different membership makes the MediaAsset
publicly reachable.

## Fallback policy

### Collection index

- HTML title: `Collections · Mediarama`
- social title: `Collections`
- description: `Browse public photo and video collections on Mediarama.`

### Collection detail

- HTML title: `{public Collection title} · Mediarama`
- social title: public Collection title
- description: trimmed public Collection description
- empty-description fallback: `Browse photos and videos in {title}.`

### MediaAsset detail

When a public title exists:

- HTML title: `{public media title} · Mediarama`
- social title: public media title

Without a public title the deterministic social/title fallback is based on the
media type: `Image`, `Video`, `Audio` or `Media`.

The public description is trimmed and whitespace-normalized. Empty
descriptions use a media-type fallback such as
`View this image on Mediarama.`.

Descriptions are not hard-truncated to a search-engine-specific character
count. Search engines may choose a different result snippet; Mediarama keeps the
source text deterministic and semantically useful.

## Open Graph

Reachable public Collection and MediaAsset pages emit:

- `og:title`
- `og:description`
- `og:url`
- `og:type=website`
- `og:site_name=Mediarama`

A public Collection cover thumbnail may be emitted as `og:image`.

For public image MediaAssets, the page uses the best currently available public
image derivative in this order:

1. `large`
2. `preview`
3. `thumbnail`

That URL is emitted as `og:image` plus `og:image:alt`.

Open Graph describes a reachable public page, so it remains available on a
reachable `noindex` page. The derivative keeps its own MediaAsset
`X-Robots-Tag` behavior.

Mediarama does not emit synthetic `og:video` metadata before a real public
video presentation/player contract exists.

## Structured data

### Collection pages

Effectively indexable Collection pages emit JSON-LD using schema.org:

- `CollectionPage`
- `BreadcrumbList` on Collection detail pages
- `ImageObject` for the primary cover only when that cover MediaAsset is
  itself effectively indexable

A Collection may be indexable while its cover MediaAsset is explicitly
`noindex`; structured discovery must not silently override the MediaAsset
policy.

### MediaAsset pages

An effectively indexable image MediaAsset with a public derivative emits one
schema.org `ImageObject` containing only deliberate public fields:

- canonical MediaAsset page as `mainEntityOfPage`;
- public derivative as `contentUrl`;
- public/fallback title;
- public/fallback description as caption;
- public width/height when known.

A reachable MediaAsset with effective `noindex` keeps canonical/Open Graph
metadata but emits no index-oriented JSON-LD.

Video/audio pages receive stable public identity and social/canonical metadata,
but no `VideoObject` or analogous rich object is emitted until Mediarama has a
real browser presentation contract with the required public content fields.

## Public metadata allowlist

Collection SEO is built only from the existing public Collection DTO.

MediaAsset SEO is built only from a dedicated public MediaAsset detail DTO. It
contains public presentation fields and current public derivative identities;
it deliberately has no Collection-membership list or raw metadata bag.

Neither path publishes:

- source/original filename;
- storage disk/key or filesystem path;
- raw EXIF/IPTC/XMP;
- exact GPS or private location data;
- internal provenance;
- hidden/private Collection membership;
- owner/contact/device-serial data.

JSON-LD is encoded server-side with JSON hex escaping before Twig renders it as
script content, preventing user-authored public text from closing or injecting
the JSON-LD script element.

## Search-index policy interaction

Canonical URL, public reachability and search indexing are separate concepts.

- `rel=canonical` identifies the preferred URL for a reachable public page.
- `X-Robots-Tag: noindex` remains the page indexability control.
- private/restricted/inaccessible resources remain unavailable and emit no page
  metadata.
- index-oriented JSON-LD is omitted when the relevant page identity is
  effectively non-indexable.
- MediaAsset SEO uses the MediaAsset policy and site default; it does not
  inherit a Collection's index/noindex preference.

An `index` preference never overrides publication/access gates.

## Verification

Integration tests cover:

- hostile Host-header canonical poisoning;
- deterministic title/description fallbacks;
- valid JSON-LD;
- Collection and MediaAsset noindex behavior;
- MediaAsset/Collection index-policy independence;
- private ancestry and site-publication 404 behavior;
- unpublished/non-ready MediaAsset blocking;
- multi-membership privacy;
- source filename/raw metadata/GPS/storage-field exclusion.

## Future work

The following stay outside this foundation:

- editable SEO-title/description overrides;
- public video poster/rendition/player routes;
- `VideoObject` and video sitemap output;
- deliberately published Smart Collection SEO;
- richer nested Collection ancestry in breadcrumbs once that public navigation
  model is finalized;
- optional human-readable MediaAsset slugs only if their redirect/migration
  complexity proves worthwhile.
