# Coppermine 1.6 vs 1.7 search and metadata comparison

Status: **verified targeted comparison**
Date: 2026-09-25
Tracking: #9, #11

Primary sources:

- current `cpg1.6.x/develop`
- `cpg1.7.x/main`

## Search subsystem

The following checked files are byte-identical between current 1.6 and 1.7:

- `search.php`
- `include/search.inc.php`
- `keywordmgr.php`
- `keyword_create_dict.php`
- `keyword_select.php`

Therefore 1.7 does **not** introduce a new search architecture or search feature set.

The mature search semantics already documented for 1.6 — field selection, AND/OR, phrases, wildcard/regex paths, owner/album/category/date filters and keyword tooling — are shared with 1.7.

### Mediarama consequence

There is no 1.7 search implementation worth treating as a target architecture.

Mediarama should preserve the useful outcomes with PostgreSQL search/filtering and a modern query model.

## Keyword administration

Because the keyword manager/dictionary/selector files are byte-identical, keyword administration is also effectively shared between the two branches.

1.7's UX experiments did not redesign the keyword subsystem.

## EXIF manager

`exifmgr.php` is byte-identical between the checked branches.

That means the administrative field-selection model and positional `show_which_exif` concept remain legacy-compatible.

Mediarama should continue using stable metadata field identifiers rather than copying the positional configuration representation.

## EXIF parser difference

`include/exif_php.inc.php` differs mainly through later 1.6 maintenance.

One verified current-1.6 safety/robustness change checks that parsed EXIF is actually an array before testing for an `Errors` key.

This is a small example of current 1.6 receiving later runtime-hardening absent from stale 1.7 code.

## IPTC supplemental categories

The most important metadata delta remains the already verified SubCategories behavior.

Current 1.6:

- calls `val_IPTC(..., false)` for IPTC 2#020;
- preserves repeated supplemental-category values as an array.

1.7:

- calls the older default form;
- effectively retains the first value rather than the repeated set.

### Display behavior

The 1.7 `displayimage.php` code defensively accepts either an array or scalar for SubCategories.

Current 1.6 assumes its corrected parser returns the array and joins it.

### Mediarama consequence

When migrating historical metadata:

- do not use 1.7's older single-value behavior as truth;
- re-extract current embedded metadata with ExifTool;
- preserve repeated IPTC fields;
- compare source DB/cache values when a real gallery is available.

## Calendar / browse-by-date

The checked `calendar.php` difference is maintenance-level.

Current 1.6 gives `$only_future_dates` a default value, fixing a PHP compatibility/deprecation issue recorded in the 2026 maintenance history.

The date-browse product behavior remains fundamentally shared.

## Thumbnail/result navigation

1.7 `thumbnails.php` adds `js/tabnav.js`.

This is the previously documented keyboard/touch pagination experiment and is a UI/navigation improvement, not a search-model change.

## Individual media viewer

1.7 `displayimage.php` similarly includes `js/tabnav.js` for keyboard/swipe media navigation.

The viewer metadata/rating logic otherwise remains largely legacy-compatible in the checked diff.

## Overall conclusion

For search and metadata:

- **search:** no meaningful 1.7 architecture change;
- **keywords:** no meaningful 1.7 architecture change;
- **EXIF admin:** unchanged;
- **IPTC:** current 1.6 contains a later correctness fix absent from 1.7;
- **viewer/result UX:** 1.7 adds useful keyboard/touch navigation.

This reinforces the branch strategy:

> use current 1.6 for corrected metadata behavior, use 1.7 only for selected navigation ideas, and keep Mediarama's independent metadata/search architecture.
