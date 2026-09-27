# Media Search

Status: authenticated/public split implemented

Mediarama does not read embedded metadata from media files during normal search. Search uses PostgreSQL fields populated during ingestion and metadata extraction.

## Public search

`GET /api/media` is the deliberately small anonymous discovery boundary.

It returns only media that are:

- not deleted;
- fully processed (`ready`);
- published;
- reachable through `effective_public_collections`.

The public DTO omits original filenames, camera details and exact GPS coordinates. The endpoint supports text, limit and offset only and remains `noindex`.

## Authenticated library search

`GET /api/library/media` requires a real authenticated actor in production.

The query applies authorization in SQL before any result DTO is produced. A media asset is eligible when:

- the actor owns the MediaAsset; or
- the asset belongs to at least one Collection in the actor-visible recursive Collection set.

The Collection set reuses `CollectionAccessSql::authenticatedVisibleCollectionsCte()`, so full ancestor visibility, private owner-only behavior, authenticated Collections, restricted user/group grants and fail-closed migrated password state stay consistent with the shared Collection access policy.

For a non-owner reaching media through a `public` Collection, the MediaAsset must also be `published`. Owners may still find their own pending/draft media. An inaccessible membership never hides the same MediaAsset when another membership is accessible.

Structured filters are applied only after the actor visibility predicate, so metadata filters cannot be used to enumerate inaccessible media.

Supported library filters:

- `q`;
- `creator`;
- `camera_make`;
- `camera_model`;
- `lens`;
- `iso_min` / `iso_max`;
- `captured_from` / `captured_until`;
- `has_location`;
- `limit` / `offset`.

The authenticated result may include the original filename and normalized photographic metadata such as camera/lens/ISO and `location_name`. Exact latitude/longitude are not returned.

## Full text

The rich library query uses the stored PostgreSQL `search_document` plus an original-filename fallback. The `simple` text-search configuration remains language-neutral for multilingual libraries.

## Next steps

- collection/tag filters;
- rating/label filters;
- cursor pagination for large libraries;
- faceting for camera/lens/date/location.
