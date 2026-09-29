# Coppermine feature and entry-point inventory

Status: **active audit — batch 1**
Date: 2026-09-25
Tracking: #11

This inventory starts from the executable/top-level surfaces in Coppermine rather than from its public navigation. The purpose is to catch mature or obscure functionality that would otherwise be missed during a UI-only review.

Primary sources:

- `coppermine-gallery/cpg1.6.x` `develop`
- `coppermine-gallery/cpg1.7.x` `main`

This is not yet a complete behavioral audit of every entry point.

## Top-level functional surfaces confirmed in 1.6

The current 1.6 tree exposes dedicated PHP entry points for at least the following product/admin capabilities.

### Gallery and media browsing

- `index.php` — gallery/category/album landing behavior
- `thumbnails.php` — album/meta-album thumbnail browsing
- `displayimage.php` — individual media viewer
- `calendar.php` — browse by date
- `search.php` — search UI/results
- `stat_details.php` — statistics/details
- `showthumb.php` — thumbnail delivery/helper behavior
- `zipdownload.php` — ZIP download workflow

### Personal/user interaction

- `addfav.php` — favorites
- `ratepic.php` — ratings
- `ecard.php` / `displayecard.php` — e-card workflow
- `contact.php` — contact form
- `report_file.php` / `displayreport.php` — report-file workflow
- `profile.php` — public/profile behavior

### Upload and ingestion

- `upload.php`
- `uniload.php`
- `upchunk.php`
- `db_input.php`
- `addpic.php`
- `searchnew.php` — batch/server-side discovery/add
- `notifyupload.php`

These must be treated as one functional family during the audit; the existence of multiple entry points does not imply that Mediarama should reproduce their request architecture.

### Media editing and management

- `edit_one_pic.php`
- `editpics.php`
- `pic_editor.php`
- `picmgr.php`
- `delete.php`

The audit still has to map exact behavior for move/copy/replace, crop/rotate, metadata edits and derivative/original mutation.

### Album/category management

- `albmgr.php`
- `modifyalb.php`
- `catmgr.php`

The migration and product audit must include normal categories, albums and the virtual user-gallery namespace.

### Identity, users and permissions

- `register.php`
- `send_activation.php`
- `forgot_passwd.php`
- `login.php`
- `logout.php`
- `usermgr.php`
- `groupmgr.php`
- `banning.php`
- `bridgemgr.php`

Bridging is a distinct capability and must not be reduced to "ordinary local authentication".

### Metadata and keyword administration

- `exifmgr.php`
- `keywordmgr.php`
- `keyword_create_dict.php`
- `keyword_select.php`

This confirms that metadata/keyword administration is part of the mature product surface, not only ingestion-time parsing.

### Moderation and communication administration

- `reviewcom.php` — comment moderation
- `db_ecard.php` — sent e-card log
- `viewlog.php` — logs

### Extensions, themes and language

- `pluginmgr.php`
- `langmgr.php`
- `lang_check.php`
- `getlang.php`

### Installation, upgrades and diagnostics

- `install.php`
- `update.php`
- `updater.php`
- `upgrader.php`
- `versioncheck.php`
- `util.php` / `util.ajax.php`
- `phpinfo.php`

The user outcome behind these tools — install, upgrade, diagnostics, maintenance, consistency/repair — matters even where Mediarama will implement it differently.

## Confirmed mature capabilities from source

### Favorites

Coppermine has a real favorites model surfaced as the `favpics` meta album.

1.6 source confirms:

- `addfav.php` updates favorites;
- anonymous/browser persistence can use a favorites cookie;
- authenticated-user persistence uses `TABLE_FAVPICS.user_favpics`;
- `include/init.inc.php` loads favorites;
- `include/functions.inc.php` treats favorites as a meta album.

Mediarama already has a normalized favorites concept. The remaining audit question is migration semantics and whether anonymous-cookie favorites are in scope.

### Browse by date

`calendar.php` and the `datebrowse` meta-album language/navigation surface confirm date-based gallery browsing as a first-class behavior.

Mediarama search/filter design must account for this separately from generic text search.

### Search and keywords

The source confirms:

- dedicated search page;
- keyword list;
- full-text search distinct from keyword-list behavior;
- clickable keyword-search configuration;
- keyword manager;
- keyword dictionary/selection helpers.

A PostgreSQL full-text index alone is therefore not enough to claim feature parity.

### E-cards

Coppermine contains:

- an e-card send/display flow;
- group-level ability to send e-cards;
- sent-e-card administration/logging.

This is likely a candidate for intentional omission or replacement by modern sharing, but it cannot be silently ignored until data/privacy implications are documented.

### File reporting

The presence of `report_file.php` and `displayreport.php` confirms an abuse/reporting workflow that still needs a full behavior audit.

### Logging

`include/logger.inc.php`, `viewlog.php` and admin configuration confirm configurable application logging and an administrative log viewer.

Mediarama does not need the same logging implementation, but observability/admin diagnosis is a product requirement candidate.

### External application bridging

`bridgemgr.php` confirms a formal bridge manager, not merely ad-hoc login integration.

The audit must cover:

- external identity authority;
- local fallback/recovery;
- group mapping;
- registration/profile implications;
- migration from a gallery whose users are bridged.

### EXIF/IPTC management

Admin surfaces and configuration expose:

- EXIF display/selection management;
- EXIF-related advanced configuration;
- IPTC-reading configuration.

Mediarama's ExifTool architecture is more modern, but field-selection behavior and migration semantics still need mapping.

## 1.7 tree-level differences confirmed so far

The repositories are overwhelmingly structurally similar. Relevant file-level differences include:

### Present in 1.6 but absent from the 1.7 tree

- legacy MySQL driver files under `include/database/mysql/`
- `include/mb.inc.php`
- SWF uploader plugin and assets
- `sidebar.php`
- `upgrader.php`

The removed SWF uploader is consistent with 1.7's cleanup direction.

### Added by 1.7

- `include/cpg.inc.php`
- `include/funcs.inc.php`
- `include/themes2.inc.php`
- `css/theme2.css`
- `js/tabnav.js`
- `themes/curve2/*`
- `themes/water_drop2/*`
- responsive-menu source/demo assets

This supports the existing conclusion that 1.7 experimented with presentation/theme cleanup rather than replacing the full application architecture.

## Audit decisions still open

This batch does **not** close any of the following:

- exact report-file workflow;
- ZIP-download semantics/permission checks;
- maintenance/repair operations in `util.php`;
- statistics/hit tracking;
- registration/activation/banning lifecycle;
- bridge implementations and supported external products;
- profile custom fields;
- language fallback/manager semantics;
- installation and upgrade compatibility guarantees;
- notification/email behaviors;
- admin bulk media operations;
- all meta albums and sort modes.

These are scheduled for subsequent batches under #11.
