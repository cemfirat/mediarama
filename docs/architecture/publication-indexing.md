# Publication, Access and Search Indexing

Status: architecture accepted  
Date: 2026-09-27

This document is the implementation guide for ADR-0015.

## The four questions

Mediarama must answer these independently:

| Question | Meaning | Example |
| --- | --- | --- |
| Deployment exposure | Where the operator intends the installation to be reachable | Internet, VPN/LAN, isolated |
| Access visibility | Who is authorized to see content | public, authenticated, private, restricted |
| Publication | Whether the media is intentionally published | draft/rejected/published |
| Search indexing | Whether a public resource should be discoverable by search engines | inherit/index/noindex |

A stricter access/publication rule always wins over indexing.

## Installer

The setup wizard should ask for a **Betriebsprofil / deployment profile**, not "Internet or Intranet?".

Recommended choices:

### Private workspace — recommended

Use when Mediarama is primarily a working library.

Initial defaults:

- public publishing: off;
- public SEO/sitemaps: off;
- site index default: noindex.

### Public publishing

Use when public galleries are a first-class purpose.

Initial defaults:

- public publishing: on;
- public SEO capability: on;
- deliberately public + published resources may follow the site index default.

Uploading still does not publish content automatically.

### Internal / isolated

Use when deployment is intended for LAN/VPN/offline environments.

Initial defaults:

- public publishing: off;
- SEO/sitemaps: off;
- search indexing: off;
- external integrations are opt-in.

This is an operational preset only; firewall/VPN/network isolation is outside Mediarama's application ACL.

## Effective public MediaAsset eligibility

A MediaAsset is eligible for public presentation only when all required public-boundary conditions are true:

1. not deleted;
2. processing state is ready;
3. moderation state is published;
4. it is reachable through at least one effectively public Collection;
5. instance public publishing is enabled.

This extends the current public-gallery boundary with a site-level public-publishing capability.

## Index-policy ownership

### Collection

The Collection owns the SEO/index preference for its own public Collection page.

### MediaAsset

The MediaAsset owns the SEO/index preference for its own public identity/detail page and, in the simple first model, the public media resource.

### Why membership does not inherit SEO

A MediaAsset can belong to zero, one or many Collections.

SEO cannot depend on whichever Collection path happened to lead to the asset.

Example:

- Media 123 belongs to Collection A and B;
- A is `noindex`;
- B is `index`.

The MediaAsset still has exactly one identity and therefore exactly one MediaAsset index preference.

Collection A's preference controls A's page. Collection B's preference controls B's page. Media 123's preference controls Media 123.

## Suggested application model

Conceptually:

```text
PlatformSettings
  public_publishing_enabled: bool
  search_index_default: index | noindex

Collection
  index_policy: inherit | index | noindex

MediaAsset
  index_policy: inherit | index | noindex
```

The exact persistence shape belongs to implementation/migration work.

The installation preset may also be stored for diagnostics/operator UX, but authorization must never trust a label such as "internal" as proof of network isolation.

## Effective indexability

Conceptually:

```text
CollectionPage:
  public_publishing_enabled
  AND collection is effectively public
  AND effective(collection index policy) == index

MediaAsset:
  public_publishing_enabled
  AND media ready
  AND media published
  AND media reachable through >= 1 effectively public collection
  AND effective(media index policy) == index
```

If any public/access prerequisite is false, effective indexability is false regardless of the saved SEO preference.

## HTTP behavior

### HTML page

For a public page that is reachable but effectively non-indexable:

```html
<meta name="robots" content="noindex">
```

Additional crawler directives may be added only when their semantics are deliberate.

### Image / video / PDF / other media response

Use:

```http
X-Robots-Tag: noindex
```

for an effectively non-indexable public media resource.

## Noindex is not private

Noindex means:

> Search engines are instructed not to keep this public resource in their index.

It does not mean:

> Unauthorized users cannot open this URL.

Authorization is the protection boundary.

The admin UI should state this clearly wherever search-engine exclusion can be changed.

## Sitemap behavior

Sitemaps are generated from the same effective policy, not from raw records.

Never include:

- authenticated/private/restricted Collections;
- unpublished media;
- noindex Collection pages;
- noindex MediaAsset pages/resources;
- ad-hoc search/facet URLs.

## Bulk actions

A Collection UI may offer:

**Contained media -> Exclude from search engines**

This is an explicit batch mutation on the selected MediaAssets.

Before applying it, the UI should disclose that an asset may also belong to other Collections. The result follows the MediaAsset everywhere because the asset has one search-index identity.

## Existing installations

When this setting is introduced, migration must preserve current reachable public behavior rather than silently hiding an established public site.

The new-installation recommendation remains Private workspace.

Existing installations should receive an explicit migration/default decision based on current public configuration and be surfaced to the administrator.

## Relationship to metadata

Search-engine indexing never means "publish all metadata".

Public HTML, structured data and sitemaps continue to use the deliberately public metadata projection defined by the public presentation layer.

Raw EXIF/IPTC/XMP/DICOM/vendor metadata does not become public merely because the MediaAsset is indexable.

Likewise, search-index policy is independent from export sanitization. ADR-0013/0014 define Privacy-safe export behavior; an indexable public MediaAsset still exposes only the deliberately public presentation projection, never its raw source snapshot.
