# Media Search

Status: actor-aware PostgreSQL implementation

Mediarama does not read embedded metadata from media files during normal search.

Search uses PostgreSQL fields populated during ingestion and metadata extraction. Public discovery and authenticated library search are deliberately separate query/DTO boundaries.

## Public discovery API

`GET /api/media`

This endpoint remains anonymously reachable and intentionally minimal.

It uses `PublicMediaSearch` and only returns media that are:

- not deleted;
- processing state `ready`;
- moderation state `published`;
- reachable through `effective_public_collections`.

The public DTO exposes deliberate presentation fields only. It does not expose original filenames, camera metadata, exact GPS coordinates, storage keys or raw embedded metadata.

Supported query parameters are currently:

- `q`;
- `limit`;
- `offset`.

The response carries `X-Robots-Tag: noindex, nofollow`; deliberate indexable gallery/SEO pages are a separate concern.

## Authenticated library API

`GET /api/library/media`

Production requires `ROLE_USER`.

The query is actor-aware before any result DTO is produced. A ready MediaAsset is eligible when either:

1. the actor owns the MediaAsset; or
2. the MediaAsset belongs to at least one Collection in the shared `actor_visible_collections` set.

For a membership that is itself effectively public, a non-owner only receives the media after `moderation_state = published`. For non-public shared Collections, ordinary viewers may work with draft/pending items but do not receive foreign `rejected` media. Media owners and Collection owners may still inspect their own/managed rejected items. Non-public authenticated/restricted Collection access remains a library capability rather than public publication.

That visible Collection set is provided by the Collection access boundary and enforces full hierarchy, owner, public/authenticated/private/restricted visibility, password-migration and user/group ACL rules.

A membership in an inaccessible Collection does not hide a MediaAsset that is independently reachable through another visible Collection.

The library response may expose normalized internal library metadata such as filename/camera/lens/location name, but it still does not expose exact latitude/longitude, raw EXIF/IPTC/XMP or storage paths.

The response is private/no-store and noindex.

## Full text

The `LibraryMediaSearch` query uses the stored PostgreSQL `search_document` generated from:

- title;
- description;
- creator;
- copyright;
- camera make/model;
- lens;
- location name;
- original filename.

A GIN index backs full-text queries. The `simple` text-search configuration is intentionally language-neutral because one installation may contain multilingual collections.

## Structured library filters

The authenticated library query supports:

- `q`;
- `creator`;
- `camera_make`;
- `camera_model`;
- `lens`;
- `iso_min`;
- `iso_max`;
- `captured_from`;
- `captured_until`;
- `has_location`;
- `limit`;
- `offset`.

Authorization remains part of the SQL query regardless of which metadata filter is used.

## Indexes

Relevant PostgreSQL indexes include:

- GIN `search_document`;
- creator;
- camera make/model;
- captured time;
- MediaAsset owner;
- active Collection parent relationships;
- reverse `collection_media(media_id, collection_id)` membership lookup.

## Next steps

- collection/tag filters;
- rating/label filters;
- cursor pagination for very large libraries;
- faceting for camera/lens/date/location;
- UI integration for authenticated library search.
