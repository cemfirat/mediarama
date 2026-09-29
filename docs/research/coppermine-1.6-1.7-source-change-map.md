# Coppermine 1.6 vs 1.7 source change map

Status: **verified repository-level comparison**
Date: 2026-09-25
Tracking: #11

This document narrows the deep audit by comparing Git blob identities across the current 1.6 `develop` tree and 1.7 `main` tree.

## Repository overlap

For paths present in both repositories:

- shared blob paths: **1398**
- byte-identical blobs by Git SHA: **1326**
- differing shared blobs: **72**

This confirms that 1.7 is overwhelmingly derived from the same codebase rather than a rewrite.

The 72 changed shared files are concentrated in:

- top-level application files: 21
- `include/`: 26
- bridges: 4
- plugins: 8
- SQL: 3
- themes: 3
- JavaScript: 2
- language files: 5

## Relevant changed shared files

Ignoring translated docs/language noise and binary assets, the comparison identifies 67 relevant changed paths:

### Top-level / application

- `albmgr.php`
- `calendar.php`
- `displayimage.php`
- `editpics.php`
- `help.php`
- `index.php`
- `install.php`
- `lang_check.php`
- `langmgr.php`
- `login.php`
- `modifyalb.php`
- `notifyupload.php`
- `register.php`
- `thumbnails.php`
- `uniload.php`
- `upchunk.php`
- `update.php`
- `usermgr.php`
- `util.ajax.php`
- plus changelog/readme

### Core includes

- `include/admin.inc.php`
- `include/captcha.inc.php`
- `include/config.inc.php.sample`
- `include/database/mysqli/install.php`
- `include/database/pdo/install.php`
- `include/dbselect.inc.php`
- `include/debugger.inc.php`
- `include/exif_php.inc.php`
- `include/functions.inc.php`
- `include/imageobject.class.php`
- `include/imageobject_gd.class.php`
- `include/imageobject_im.class.php`
- `include/imageobject_imx.class.php`
- `include/init.inc.php`
- `include/inspekt.php`
- `include/inspekt/cage.php`
- `include/inspekt/error.php`
- `include/inspekt/supercage.php`
- `include/iptc.inc.php`
- `include/makers/nikon.php`
- `include/picmgmt.inc.php`
- `include/plugin_api.inc.php`
- `include/themes.inc.php`
- `include/tool.class.php`
- `include/upgrader.inc.php`
- `include/versioncheck.inc.php`

### Bridges

- `bridge/coppermine.inc.php`
- `bridge/smf20.inc.php`
- `bridge/smf21.inc.php`
- `bridge/udb_base.inc.php`

### Upload plugins

- `plugins/upload_h5a/*` relevant code/config/help/JS
- `plugins/upload_sgl/codebase.php`
- `plugins/upload_sgl/configuration.php`
- `plugins/visiblehookpoints/codebase.php`

### SQL

- `sql/basic.sql`
- `sql/schema.sql`
- `sql/update.sql`

### Themes / JS

- `themes/curve/theme.php`
- `themes/hardwired/theme.php`
- `themes/sample/theme.php`
- `js/albmgr.js`
- `js/scripts.js`

## Files unique to current 1.6 with architectural significance

Relevant examples:

- legacy MySQL driver files
- `sidebar.php`
- `upgrader.php`
- SWF upload plugin and assets
- current `SECURITY.md`

## Files unique to 1.7 with architectural significance

Relevant examples:

- `include/cpg.inc.php`
- `include/funcs.inc.php`
- `include/themes2.inc.php`
- `css/theme2.css`
- `js/tabnav.js`
- `themes/curve2/*`
- `themes/water_drop2/*`
- responsive-menu experimental/demo assets

## Audit strategy from this map

The source comparison is now bounded enough to be systematic.

Every changed shared file does **not** require equal depth. The priority order is:

1. SQL/schema/config;
2. upload/media processing;
3. authorization/identity/bridges;
4. metadata;
5. search/browse;
6. plugin API;
7. themes/navigation;
8. admin/maintenance;
9. install/update/version handling.

Byte-identical files are still relevant for product inventory, but they generally do not need a separate 1.6-vs-1.7 semantic diff because their source is identical.

## Already verified deltas

From deeper file-level inspection so far:

- 1.7 adds `pictures.mime` and `pictures.ftype`;
- 1.7 adds default file types WebP/WebM/WebA;
- current 1.6 later expanded hit/vote statistic IP fields to 40 chars for IPv6, while 1.7 remains 20 chars;
- current 1.6 has a later IPTC SubCategories repeatability fix absent from 1.7;
- 1.7 media insertion populates `mime` and `ftype`;
- 1.7 contains Theme2/responsive experiments;
- 1.7 removed the SWF uploader;
- current 1.6 contains later maintenance/security/PHP compatibility work.

## Important conclusion

This map reinforces a nuanced position:

> 1.7 contains selected forward experiments, but current 1.6 contains later maintenance knowledge. Neither branch should be copied wholesale; both must be audited by capability.

The remaining audit will use this file map as the checklist for changed-code review.
