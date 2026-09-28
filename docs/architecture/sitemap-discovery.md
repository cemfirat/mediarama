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

It references two bounded sitemap families:

- `/sitemaps/collections-{page}.xml`
- `/sitemaps/media-{page}.xml`

Collection chunks contain at most 250 indexable Collection pages. This conservative bound leaves substantial room below generic sitemap size limits even when a Collection page contributes many image entries.

Media chunks contain at most 1000 stable public MediaAsset detail URLs. A MediaAsset appears at most once per sitemap family even when it belongs to multiple public Collections.

The first Collection chunk may also include the public `/collections` root page when the site-level search-index default is `index`.

The sitemap index omits the MediaAsset family entirely when no MediaAsset currently satisfies the effective public/indexable boundary.

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

A deliberately published Smart Collection follows the same Collection URL and
index-policy rules. Its rule is resolved dynamically only for media shown on
that page; rule JSON is never emitted into XML.

For Smart Collection image discovery, Mediarama uses the first 120 dynamically
ordered page results, then independently requires the normal public MediaAsset
boundary and effective MediaAsset index policy. Private/restricted matching
MediaAssets therefore cannot enter the sitemap merely because a Smart rule
matches them.

## MediaAsset detail-page discovery

Mediarama now has a stable public MediaAsset HTML identity:

`GET /media/{id}`

The normal XML sitemap therefore lists an effectively public/indexable MediaAsset by that canonical detail URL, not by a versioned derivative URL and not by one of its Collection memberships.

A MediaAsset detail URL requires:

1. site public publishing is enabled;
2. the MediaAsset is not deleted;
3. processing state is `ready`;
4. moderation state is `published`;
5. at least one membership resolves through `effective_public_collections`;
6. the MediaAsset's own effective `inherit | index | noindex` policy resolves to `index`.

The query uses an `EXISTS` visibility test rather than joining result rows to every membership. Multiple public Collection memberships therefore still produce one MediaAsset sitemap URL.

Collection index policy is deliberately independent. A `noindex` Collection does not remove an otherwise indexable MediaAsset detail page as long as the asset remains reachable through an effectively public membership.

The MediaAsset sitemap query normally needs only the public route identity.
For qualifying videos it additionally selects the intentionally public
title/description, canonical duration, truthful first-publication time and the
latest complete poster/browser-MP4 presentation version. It never selects
source filenames, original storage keys, raw metadata, GPS values, private
Collection names or publication-provenance source identifiers.

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

## Video discovery

The existing bounded MediaAsset sitemap family carries Google's video sitemap
extension on a canonical MediaAsset entry only when the video has:

- the normal effective public/indexable MediaAsset boundary;
- a complete matching generated poster + browser-MP4 presentation version;
- a non-null truthful `public_published_at`.

The parent `<loc>` remains the stable `/media/{id}` watch page. The video
extension uses:

- generated poster derivative as `video:thumbnail_loc`;
- the same public/fallback title and description semantics as `VideoObject`;
- generated browser MP4 as `video:content_loc`;
- canonical duration in whole seconds when it is inside Google's supported
  1–28800 second range;
- persisted first-publication time as `video:publication_date`.

A public/indexable video with unknown publication time remains a normal
MediaAsset sitemap URL but receives no `video:video` extension. This preserves
canonical page discovery without fabricating video-rich metadata.

MediaAsset `noindex`, inaccessible ancestry, unpublished/non-ready state or
site publication-off remove the MediaAsset from sitemap discovery entirely.

### No synthetic lastmod

The protocol's `lastmod` describes the actual modification time of the listed page, not the time at which a sitemap is generated.

Until Mediarama tracks a truthful Collection-page modification time across Collection edits, membership changes and relevant media presentation changes, the optional field is omitted.

## Privacy verification

Integration coverage proves that the sitemap:

- excludes Collection pages with `noindex`;
- excludes MediaAsset detail pages with `noindex`;
- keeps MediaAsset detail indexing independent from Collection page indexing;
- deduplicates one MediaAsset across multiple public memberships;
- excludes private-only/inaccessible media and Collection ancestry;
- excludes unpublished media;
- removes the entire discovery surface when public publishing is off;
- never emits ad-hoc public search URLs;
- never emits a deliberately inserted private metadata sentinel or private Collection title;
- emits video extension data only for complete public presentations with
  truthful first-publication time;
- keeps `VideoObject` and video-sitemap poster/content/title/description/date
  semantics aligned;
- never leaks original video storage identity or publication provenance source.

The tests also send forged `Host` headers to both sitemap families and verify that every generated URL remains anchored to `PUBLIC_BASE_URL`.
