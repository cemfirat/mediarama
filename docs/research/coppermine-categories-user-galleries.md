# Coppermine categories and virtual user galleries audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #8, #11

Primary sources:

- current 1.6 `sql/schema.sql`
- `sql/basic.sql`
- `catmgr.php`
- `index.php`
- `profile.php`
- `register.php`
- `delete.php`
- `include/functions.inc.php`
- current official Categories/Albums documentation

## Ordinary category hierarchy

Categories are organizational containers for albums.

They can contain:

- subcategories;
- albums.

They do not directly contain picture/file rows.

The category table stores both:

- adjacency: `parent`;
- nested-set/tree cache: `lft`, `rgt`, `depth`;
- ordering: `pos`.

### Migration implication

The importer should treat `parent`/category identity as the semantic hierarchy and validate nested-set fields as derived consistency data.

Preflight should detect:

- missing parent;
- cycles;
- duplicate/impossible nested-set ranges;
- depth mismatch;
- orphan categories.

Mediarama should build its own target hierarchy rather than copy stale nested-set coordinates blindly.

## Root/no-category albums

An album can live in the gallery root rather than inside an ordinary category.

This is represented separately from the ordinary category tree and must remain a valid migration case.

The target collection model should not invent a fake visible category solely to preserve this source storage choice unless Mediarama's navigation model requires a root collection container internally.

## Special category 1: User galleries

Coppermine seeds category ID `1` as:

`User galleries`

This is a **special namespace**, not the parent row for a normal child-category-per-user model.

The category manager protects it specially:

- it is not treated like an ordinary movable/deletable category;
- normal category-parent choices exclude the special ID in relevant paths.

## Virtual user-gallery IDs

Actual user gallery album placement uses the convention:

`FIRST_USER_CAT + user_id`

where:

`FIRST_USER_CAT = 10000`

Example:

- user ID 23 → virtual gallery category 10023.

There is no required physical `categories.cid = 10023` row.

This is one of the most important non-relational Coppermine conventions.

## Personal albums

When `personal_album_on_registration` is enabled, registration can immediately create a personal album with:

- owner = new user ID;
- category = `FIRST_USER_CAT + user_id`.

Users can also manage personal-gallery albums depending on effective group capability.

### Migration implication

The current Mediarama importer direction is correct:

- recognize virtual user category IDs;
- group those albums under the mapped user rather than looking for a missing ordinary category row.

A naive FK-style category lookup would incorrectly classify these albums as orphaned.

## User gallery as a virtual aggregate

Coppermine treats "User galleries" as an aggregate of all per-user virtual gallery namespaces.

Code paths query albums whose category is above `FIRST_USER_CAT`, then group/present them by user.

This is conceptually closer to a user-owned library/profile section than to a normal site taxonomy category.

### Mediarama direction

Do not recreate `10000 + user_id` IDs in the target.

Represent:

- user ownership explicitly;
- optional user library/root collection explicitly;
- user profile/gallery navigation as a query/view over owned collections/media.

## User-gallery representative media

`pictures.galleryicon` can designate a user's representative gallery/media icon.

When a new one is selected, Coppermine clears the previous gallery-icon flag for that owner.

User/profile listing code can use:

- explicit gallery icon;
- otherwise recent/derived media as fallback.

### Migration implication

Do not lose this as a meaningless picture boolean.

Possible target mapping:

- user/profile cover/avatar-like gallery representative;
- or a migration hint for user-library cover.

If Mediarama does not expose this concept in 1.0, preserve it as legacy presentation metadata so it can be reviewed.

## Category thumbnails

Ordinary categories have a `thumb` picture reference.

The category manager allows choosing representative media from albums in the category, including media effectively linked through album keywords.

For the special User galleries aggregate, it can select among media belonging to virtual user galleries.

### Migration implication

Validate `categories.thumb` against imported media.

If the source thumbnail target is missing or inaccessible:

- do not fail the whole hierarchy import;
- report it;
- choose a safe target fallback policy.

## Album thumbnails/covers

Albums independently have `albums.thumb`.

Therefore Coppermine has separate presentation concepts for:

- category representative image;
- album cover;
- user gallery icon.

Mediarama should not collapse all three blindly into one field.

## Category ownership

The category table contains `owner_id`, even though category administration is primarily an administrator function in the normal UI.

This field should be treated as source data requiring validation rather than assumed to define the same ownership semantics as album/user gallery ownership.

Real-gallery preflight should report non-zero custom category owners.

## Category-scoped creation rights

`categorymap` maps:

- category ID;
- group ID.

This grants groups the ability to create/manage public albums in selected categories.

This is independent from:

- category hierarchy;
- album visibility;
- user personal-gallery capability.

It is already documented as a blocking ACL migration gap.

## User album management

Non-admin album manager/delete behavior combines:

- personal-gallery capability;
- virtual user-gallery category ownership;
- album owner;
- categorymap-allowed public categories;
- global settings such as moving albums between allowed categories.

Mediarama therefore needs resource-scoped collection management, not only a global "create collection" role.

## User deletion lifecycle

Deleting a user can delete their albums in the virtual user-gallery namespace.

Public-gallery uploads owned by that user can be separately deleted or anonymized.

This distinction matters:

- personal collections are user-owned structural data;
- media uploaded into public collections may outlive the user with ownership anonymized/transferred.

Mediarama account-erasure policy should preserve this distinction.

## User profile/gallery navigation

Profiles expose gallery-derived information such as:

- picture count;
- personal album count;
- recent upload/media thumbnail;
- recent comment activity;
- links into user-specific meta-album views.

This is another reason not to model "user gallery" as only a hidden category number.

It is a product-level user-library/profile concept.

## Album keyword interaction with categories

Category result sets can include pictures linked into albums by album keywords.

Therefore category migration verification must compare **effective visible membership**, not only albums whose `category` equals a given CID and pictures whose `aid` equals those albums.

## 1.6 vs 1.7

The 1.7 branch does not replace this virtual-user-category architecture.

The same broad category/album conventions remain.

Mediarama should therefore transform them rather than preserve numeric conventions.

## Migration preflight requirements

Report:

- ordinary category count;
- root albums;
- nested category maximum depth;
- hierarchy inconsistencies;
- category thumbnails pointing at missing media;
- non-zero category owner IDs;
- user-gallery albums by virtual user ID;
- virtual user galleries whose source user is missing;
- user gallery icon rows;
- categorymap rules;
- album keyword links affecting category results.

## Tests required

- root album;
- single-level category;
- deeply nested categories;
- custom ordering;
- missing parent;
- inconsistent nested-set state;
- user personal album;
- multiple personal albums;
- missing source user for virtual user category;
- category thumbnail;
- user gallery icon;
- categorymap rights;
- album-keyword-linked media in a category result.

## Conclusion

Coppermine categories include two different concepts:

1. ordinary hierarchical site taxonomy;
2. a special virtual namespace for user-owned galleries.

Mediarama should preserve both user outcomes while replacing the implicit numeric conventions with explicit hierarchy and ownership.
