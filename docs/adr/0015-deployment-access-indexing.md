# ADR-0015: Separate deployment exposure, access, publication and search indexing

- Status: Accepted
- Date: 2026-09-27
- Tracks: #94
- Related: ADR-0009, #13, #35, #58

## Context

A self-hosted Mediarama installation may be:

- reachable on the public Internet;
- reachable only through a private network/VPN;
- completely isolated;
- internet-facing while requiring authentication for all content;
- internet-facing with a mixture of public and private Collections.

These are different operational states.

Likewise, a public page may be reachable but intentionally excluded from search engines, while a private page must be protected by authorization regardless of any crawler directive.

A single "Internet / Intranet" switch therefore cannot represent the real policy.

## Decision

Mediarama separates four concerns:

1. **deployment exposure** — where/how operators intend the installation to be reachable;
2. **access visibility** — who may access a Collection/resource;
3. **publication state** — whether a media item is ready/published for public presentation;
4. **search indexing** — whether an effectively public resource should participate in search-engine discovery.

No layer may silently override a stricter layer below it.

## Installation presets

The installer may offer three understandable presets.

### Private workspace — default

Recommended default for new installations.

- authenticated/private use is the default expectation;
- anonymous publication is disabled by default;
- public SEO/sitemaps are disabled by default;
- search indexing defaults to `noindex`.

The server may still be internet-facing behind authentication.

### Public publishing

For installations intentionally publishing galleries/content.

- anonymous public routes may be enabled;
- effectively public + published resources may become indexable;
- sitemap/SEO capabilities may be enabled;
- upload itself never implies publication or indexing.

### Internal / isolated

For LAN/VPN/offline-style deployments.

- anonymous publication disabled by default;
- search-engine discovery disabled;
- external/public integrations are opt-in;
- the application does not claim to enforce actual network isolation.

These presets initialize settings. They are not a permanent hidden "mode" that bypasses the normal policy model.

## Deployment exposure is not authorization

The application cannot prove that a server is truly on an intranet merely from a setting.

Therefore deployment exposure is operational/configuration intent, not an ACL.

Authorization continues to use the existing Collection access policy.

## Access visibility

Reuse the existing explicit Collection semantics:

- `public`;
- `authenticated`;
- `private`;
- `restricted`.

A private/authenticated/restricted resource is never made indexable by an SEO preference.

The effective-public ancestor rule remains authoritative.

## Publication

Media publication/moderation remains independent from access visibility.

Public discovery requires at minimum:

- processing state ready;
- moderation/publication state published;
- reachability through at least one effectively public Collection;
- site-level public publishing enabled.

An upload/import is not automatically public.

## Search-index policy

Public Collection pages and MediaAsset pages may have:

- `inherit`;
- `index`;
- `noindex`.

The site has a default index policy.

Effective indexability is always forced off if the resource is not effectively public/published.

### No Collection-to-MediaAsset inheritance

A MediaAsset can belong to multiple Collections.

Therefore its SEO preference must not implicitly inherit from a Collection membership. Otherwise two memberships with different policies would produce an arbitrary result.

Instead:

- site default -> Collection policy controls the Collection page;
- site default -> MediaAsset policy controls the MediaAsset identity/detail page;
- Collection membership decides whether the MediaAsset is effectively public;
- an explicit Collection bulk action may set MediaAsset policies, but that is a mutation on those MediaAssets, not hidden inheritance.

This preserves stable resource identity and the no-duplication model.

## Page versus binary/media resource

HTML pages and the underlying image/video/PDF resource are distinct search-engine targets.

The first product version may expose one simple MediaAsset control:

**Search engines**

- Use site default
- Allow indexing
- Exclude from search engines

When "Exclude" is selected, both the public detail page and public media resource should be treated as non-indexable by default.

A future advanced control may split page indexing from binary/media-resource indexing if a concrete requirement exists.

## Robots implementation

### HTML

Effective `noindex` is emitted using a robots meta directive and/or `X-Robots-Tag`.

### Non-HTML media

Images, PDFs, video and other non-HTML resources use the HTTP `X-Robots-Tag` response header.

### robots.txt is not privacy

`robots.txt` is crawl guidance, not authorization.

Do not use `Disallow` as the mechanism for private content or as the only way to express `noindex`.

Private/restricted content remains protected by authorization and should not be linked from public discovery surfaces.

## Sitemaps and structured data

A resource enters public sitemap/index-oriented discovery only when it is:

- effectively public;
- published/ready where applicable;
- public-publishing eligible;
- effectively indexable.

Private/authenticated/restricted/noindex content is excluded.

Structured data continues to use deliberately public Mediarama fields rather than raw embedded metadata.

## Safe override rule

The effective policy is monotonic toward safety.

Examples:

- private + `index` => not indexable;
- unpublished + `index` => not indexable;
- site public publishing disabled + `index` => not indexable;
- effectively public + published + `noindex` => reachable but not deliberately discoverable;
- effectively public + published + `index` => eligible for indexing.

## UI terminology

Prefer:

- **Betriebsprofil / Deployment profile**
- **Öffentliche Veröffentlichung / Public publishing**
- **Zugriff / Access visibility**
- **Suchmaschinen-Indexierung / Search indexing**

Do not label "private" as "intranet"; they are not equivalent.

## Consequences

Positive:

- safe installer defaults;
- search-engine controls cannot become an ACL bypass;
- internet-facing private deployments are represented correctly;
- public installations may still exclude individual media;
- multi-membership does not make indexability ambiguous;
- SEO and access remain testable as separate concerns.

Trade-offs:

- public publishing/indexing needs explicit site settings;
- Collection and MediaAsset SEO preferences are separate;
- bulk "exclude all media in this Collection" requires an explicit operation rather than implicit membership inheritance.

## Verification requirements

Implementation must test:

- each installation preset's resulting defaults;
- public-publishing global override;
- public/authenticated/private/restricted visibility;
- published/unpublished media;
- Collection page index policy;
- MediaAsset index policy under multiple Collection memberships;
- HTML robots directives;
- media-resource `X-Robots-Tag`;
- sitemap exclusion;
- private/restricted resources never becoming indexable.
