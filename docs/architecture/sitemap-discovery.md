# Public Sitemap Discovery

Status: first-release production boundary  
Date: 2026-09-27

Mediarama exposes search-engine discovery only from the same effective publication/indexing policy used by public HTML and media delivery.

## Standards basis

The implementation follows:

- the [Sitemaps protocol](https://www.sitemaps.org/protocol.html);
- [Google Search Central — Build and submit a sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap);
- [Google Search Central — Image sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps);
- [Google Search Central — Video sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps).

Important implementation consequences:

- sitemap URLs are absolute;
- XML is UTF-8 and entity-escaped;
- sitemap files stay bounded rather than assuming an unlimited URL/media count;
- image discovery uses the image sitemap namespace and `image:loc`;
- sitemap inclusion is a discovery hint, never an authorization mechanism.

## Routes

`/sitemap.xml` is the stable sitemap index.

It references bounded Collection sitemap chunks:

`/sitemaps/collections-{page}.xml`

Each chunk contains at most 250 indexable Collection pages. This conservative bound leaves substantial room below generic sitemap size limits even when a Collection page contributes many image entries.

The first chunk may also include the public `/collections` root page when the site-level search-index default is `index`.

## Canonical public origin

Sitemap URLs must not depend on an arbitrary HTTP `Host` header.

The deployment therefore configures:

`PUBLIC_BASE_URL=https://gallery.example.com`

Requirements:

- absolute `http` or `https` origin;
- scheme + host, optional port;
- no path, query, fragment or embedded credentials.

This value is intended to become the common origin for future canonical/Open Graph URL generation as #13 expands.

## Collection inclusion

A Collection sitemap URL requires all of the following:

1. site public publishing is enabled;
2. the Collection belongs to `effective_public_collections`, so its complete access ancestry is public;
3. the Collection's own effective `inherit | index | noindex` policy resolves to `index`.

A child cannot re-enter the sitemap through an inaccessible parent.

The Collection policy controls the Collection page only. It does not silently change the MediaAsset's SEO preference.

## Image discovery

The current Collection page renders up to 120 public media items.

Image sitemap entries are therefore derived from those same first 120 public page items, not from every database membership. This prevents the sitemap from claiming that an image exists on a page where pagination has not rendered it.

An image entry additionally requires:

- MediaAsset not deleted;
- processing state `ready`;
- moderation state `published`;
- media type `image`;
- MediaAsset effective search-index policy resolves to `index`;
- a public `preview` derivative, falling back to `thumbnail`.

Only the derivative URL is emitted. Titles, descriptions, GPS, raw metadata, storage keys and source snapshots are not selected by the sitemap query.

A MediaAsset may appear on more than one indexable Collection page. Repeating its image entry under each real host page is intentional and does not create a second MediaAsset identity.

## What is intentionally absent

### No MediaAsset canonical page yet

Mediarama currently has public Collection pages and versioned derivative URLs, but no stable public MediaAsset detail route.

The normal sitemap therefore does not invent a pseudo-canonical MediaAsset page or treat a versioned derivative URL as the MediaAsset's permanent identity.

### No video sitemap yet

The public gallery does not yet expose a stable public video player/content URL. Google video sitemap metadata requires a real host page plus accessible thumbnail/player or content targets.

Video sitemap support belongs with the future public video presentation route, not with a fabricated placeholder.

### No synthetic lastmod

The protocol's `lastmod` describes the actual modification time of the listed page, not the time at which a sitemap is generated.

Until Mediarama tracks a truthful Collection-page modification time across Collection edits, membership changes and relevant media presentation changes, the optional field is omitted.

## Privacy verification

Integration coverage proves that the sitemap excludes:

- Collection pages with `noindex`;
- MediaAssets with `noindex`;
- private/inaccessible Collection ancestry;
- unpublished media;
- the entire discovery surface when public publishing is off;
- ad-hoc public search URLs;
- a deliberately inserted private metadata sentinel.

The tests also send a forged `Host` header and verify that generated URLs remain anchored to `PUBLIC_BASE_URL`.
