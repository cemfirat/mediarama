# Coppermine album membership, ownership and category-rights audit

Status: **active audit**
Date: 2026-09-25
Tracking: #8, #11

## Native album membership

The ordinary Coppermine relation is:

`pictures.aid → albums.aid`

That is only part of the effective membership model.

## Album keywords create additional effective membership

An album can have an `albums.keyword`.

Coppermine includes media in album/category views when picture `keywords` match an album keyword, even when the picture's native `aid` points to another album.

Source examples build conditions such as:

`aid = <album> OR keywords LIKE '%<album keyword>%'`

This means:

> one physical/source picture can effectively appear in multiple album views without duplicating the picture row.

That is conceptually close to Mediarama's many-to-many Collection ↔ Media model.

## Migration consequence

The current importer maps native `pictures.aid` to `collection_media`, but does not yet materialize album-keyword membership.

A complete migration must:

1. read every non-empty album keyword;
2. evaluate source picture keyword semantics using the source separator/rules;
3. add the matching MediaAsset to that album's Collection;
4. avoid duplicate links where native and keyword membership overlap;
5. preserve ordering rules where meaningful;
6. reconcile effective source membership counts, not only `pictures.aid` counts.

This is a **blocking migration gap** until implemented/tested.

## Category propagation

Category-level/meta-album queries can collect album keywords from albums beneath a category and include keyword-linked pictures in category results.

Therefore migration tests need to compare visible result sets, not only database foreign keys.

## Album ownership and public-gallery control

Coppermine has settings that let users retain control over media they uploaded into public galleries.

Confirmed configuration includes:

- `users_can_edit_pics` — retain control over own files in public galleries;
- `allow_user_move_album` — move owned albums among allowed categories;
- `allow_user_album_keyword` — assign album keywords;
- `allow_user_edit_after_cat_close` — edit owned album even after category permissions change.

This demonstrates resource-ownership semantics beyond static group permissions.

## Category-scoped creation rights

The `categorymap` table maps category + group.

It is consulted to decide:

- which public categories a user can create albums in;
- which categories appear in album-management choices;
- whether an owned public album remains editable;
- category-aware delete/edit authorization.

This is separate from album visibility and from a global `can_create_albums` bit.

## Mediarama authorization implication

A future resource policy should distinguish at least:

- `collection.view`
- `collection.create_child`
- `collection.edit`
- `collection.delete`
- `collection.media.add`
- `collection.media.manage`
- owner privileges/policy

Global roles alone are not enough to represent Coppermine's mature behavior.

## Virtual user-gallery categories

Coppermine reserves IDs starting at `FIRST_USER_CAT = 10000` for per-user gallery namespaces.

These IDs are not normal category rows.

The current importer already avoids trying to resolve them as ordinary categories, but the final UX/migration audit still needs to determine how to preserve:

- user-gallery grouping;
- ownership;
- URLs/navigation;
- public/private visibility;
- category-level counts/thumbs;
- user profile → gallery navigation.

## Mediarama product opportunity

Unlike Coppermine, Mediarama already has n:m Collection ↔ Media semantics.

That lets Mediarama preserve album-keyword outcomes in a cleaner form:

- original asset remains unique;
- multiple Collection links become explicit;
- no substring keyword query is required at gallery render time;
- future auto/smart collections can be a separate intentional feature.

This is an example where Coppermine product knowledge should directly influence migration while Mediarama keeps its own model.
