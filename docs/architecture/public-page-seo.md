# Public Collection SEO boundary

Status: implemented foundation
Date: 2026-09-28

This document defines canonical, social and structured metadata for the public
Collection HTML surfaces that Mediarama currently exposes.

It deliberately does not invent a public MediaAsset detail page merely to fill
an SEO checklist. MediaAsset page canonicals and video-specific discovery belong
to a future stable public media route.

## Trusted canonical origin

Every canonical and social URL is generated from the configured
`PUBLIC_BASE_URL`.

Request `Host`, forwarded host values and arbitrary browser input are not
canonical-origin inputs. `PublicUrlGenerator` validates that the configured
value is an absolute HTTP(S) origin without path, credentials, query or
fragment, then combines it with application-generated absolute paths.

This is the same trust boundary already used by sitemap discovery.

## Current public pages

The initial metadata surface covers:

- `GET /collections`
- `GET /collections/{id}`

A page still has to pass the normal public-publishing and Collection-access
boundary before metadata is rendered. Canonical metadata never grants access.

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

Descriptions are not hard-truncated to a search-engine-specific character
count. Search engines may choose a different result snippet; Mediarama keeps the
source text deterministic and semantically useful.

## Open Graph

Reachable public Collection pages emit:

- `og:title`
- `og:description`
- `og:url`
- `og:type=website`
- `og:site_name=Mediarama`

A public Collection cover thumbnail is emitted as `og:image` plus
`og:image:alt` when one is available through the existing public-gallery DTO.

Open Graph describes a reachable public page. It is therefore retained on a
reachable `noindex` page. The media derivative keeps its independent
MediaAsset robots/index policy.

## Structured data

Effectively indexable Collection pages emit JSON-LD using schema.org:

- `CollectionPage`
- `BreadcrumbList` on Collection detail pages
- `ImageObject` for the primary cover only when that MediaAsset is itself
  effectively indexable

This last rule is intentional. A Collection may be indexable while its cover
MediaAsset is explicitly `noindex`; structured discovery must not silently
override the MediaAsset policy.

Reachable Collection pages that are effectively `noindex` keep their
canonical and Open Graph metadata but do not emit index-oriented JSON-LD.

## Public metadata allowlist

SEO output is built only from the existing public Collection presentation
boundary:

- public Collection title
- public Collection description
- public route identity
- public cover derivative identity/version

It does not query or publish raw EXIF/IPTC/XMP, GPS, source filenames, storage
keys, filesystem paths, private Collection data or hidden ancestry.

The JSON-LD string is encoded server-side with JSON hex escaping before Twig
renders it as script content, preventing user-authored public text from closing
or injecting the JSON-LD script element.

## Search-index policy interaction

Canonical URL and access visibility are separate concepts.

- `rel=canonical` identifies the preferred URL for a reachable public page.
- `X-Robots-Tag: noindex` remains the indexability control for reachable
  non-indexable pages.
- private/restricted/inaccessible pages remain unavailable and therefore emit
  no page metadata.
- JSON-LD is omitted when the Collection page is effectively non-indexable.

An `index` preference never overrides the existing publication/access gates.

## Future work

The following stay outside this foundation:

- editable SEO-title/description overrides;
- public MediaAsset detail-page canonical/schema output;
- video-player routes and video sitemap/VideoObject output;
- deliberately published Smart Collection SEO;
- richer nested Collection ancestry in breadcrumbs once that public navigation
  model is finalized.
