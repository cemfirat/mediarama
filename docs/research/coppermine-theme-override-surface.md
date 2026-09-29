# Coppermine theme override surface audit

Status: **verified major override-mechanism audit**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current 1.6 `include/init.inc.php`
- `include/themes.inc.php`
- `themes/sample/theme.php`
- `themes/curve/template.html`
- bundled themes
- 1.7 Theme2 files for branch comparison

## Theme loading order

Coppermine deliberately loads the configured theme before core fallbacks:

1. resolve selected theme;
2. load `themes/<theme>/theme.php`;
3. load `include/themes.inc.php`.

The fallback file conditionally declares functions/templates only when the theme did not already provide them.

This is the core override mechanism.

A theme therefore overrides behavior by defining a function/template variable before the fallback layer loads.

## Function override surface

The bundled sample theme mirrors the core theme function surface.

The checked source contains roughly three dozen major callable override points, including:

- page header/footer;
- standard table start/end;
- system/sub/admin menus;
- breadcrumb rendering;
- category/album list rendering;
- thumbnail rendering;
- filmstrip;
- media viewer;
- full-size media display;
- image navigation;
- media information;
- comments;
- rating UI;
- slideshow;
- messages/errors;
- credits/vanity;
- JavaScript head generation.

Representative functions include:

- `pageheader()`
- `pagefooter()`
- `theme_main_menu()`
- `theme_admin_mode_menu()`
- `theme_display_cat_list()`
- `theme_display_album_list()`
- `theme_display_thumbnails()`
- `theme_display_film_strip()`
- `theme_display_image()`
- `theme_display_fullsize_pic()`
- `theme_html_picture()`
- `theme_html_picinfo()`
- `theme_html_comments()`
- `theme_html_rating_box()`
- `theme_slideshow()`
- `theme_cpg_die()`

The 1.7 ordinary `include/themes.inc.php` exposes the same function-name set in the checked comparison. Theme2 is an additional experiment, not replacement of this extension mechanism.

## Template-variable override surface

Themes can also replace large template strings.

The sample theme exposes template variables for areas such as:

- system/sub menus;
- gallery/user admin menus;
- category/album lists;
- breadcrumb;
- thumbnail views and title rows;
- favorite thumbnail views;
- filmstrip;
- media display;
- image navigation;
- image information;
- comments/add-comment UI;
- ratings;
- message/error boxes;
- e-cards;
- reports;
- ZIP README/plaintext output;
- user list;
- sidebar;
- header/footer.

This makes the presentation API broad but weakly typed.

## Outer HTML shell

`template.html` provides the outer page skeleton.

Confirmed tokens in the standard Curve template include:

- `{CHARSET}`
- `{LANG_DIR}`
- `{TITLE}`
- `{META}`
- `{JAVASCRIPT}`
- `{CUSTOM_HEADER}`
- `{GAL_NAME}`
- `{GAL_DESCRIPTION}`
- `{SYS_MENU}`
- `{SUB_MENU}`
- `{ADMIN_MENU}`
- `{GALLERY}`
- `{MESSAGE_BLOCK}`
- `{CUSTOM_FOOTER}`
- `{CREDITS}`

This is a genuine shell/content separation mechanism, but it remains string/token based.

## Theme capability constants

Themes can declare feature constants that change core rendering/asset lookup.

Observed examples include:

- `THEME_HAS_RATING_GRAPHICS`
- `THEME_HAS_NAVBAR_GRAPHICS`
- `THEME_HAS_PROGRESS_GRAPHICS`
- `THEME_HAS_FILM_STRIP_GRAPHICS`
- `THEME_HAS_SIDEBAR_GRAPHICS`
- `THEME_HAS_MENU_ICONS`
- `THEME_HAS_NO_SYS_MENU_BUTTONS`
- `THEME_HAS_NO_SUB_MENU_BUTTONS`

These constants can switch which asset directory or rendering branch core uses.

## Theme selection can be dynamic

Theme selection can come from:

- configured default;
- user/browser theme state;
- URL theme override under validated naming rules;
- the plugin `theme_name` filter.

This creates a flexible preview/switching model.

Mediarama can support previewable themes later, but should not let themes or URL parameters alter application authorization/business logic.

## Why the separation is incomplete

The override mechanism is powerful because entire PHP functions can be replaced.

That is also its main architectural weakness.

Theme PHP can contain:

- permission checks;
- user/group decisions;
- SQL/data assumptions;
- URL construction;
- plugin hook calls;
- interaction logic;
- global-variable coupling.

The presentation boundary is therefore convention, not enforcement.

## Relationship with plugins

Themes and plugins can both affect presentation.

Plugins can filter:

- menus;
- page HTML/meta;
- JavaScript includes;
- theme selection;
- thumbnail captions/data;
- viewer HTML;
- theme-specific parameter sets.

This makes the effective UI the composition of:

- core fallback theme code;
- selected theme PHP;
- template strings;
- outer template.html;
- plugin filters/actions;
- global configuration.

It is flexible but difficult to reason about and test.

## 1.7 Theme2

The separate 1.7 Theme2 experiment adds useful modern layout/navigation ideas:

- Grid/Flex;
- responsive hamburger menus;
- touch/pointer swipe;
- keyboard navigation.

It still sits inside the same broad theme-function model and does not create a clean application/presentation boundary.

## Mediarama equivalent

Mediarama should preserve theme capability while reducing override power to presentation concerns.

Target boundary:

`Controller / Query / Authorization → View Model → Twig → UIkit components/theme assets`

A theme may control:

- layout;
- typography;
- component templates;
- spacing;
- visual variants;
- asset bundle;
- optional named UI slots.

A theme should **not** control:

- SQL;
- authorization;
- media processing;
- import behavior;
- user/session state;
- mutation/business rules.

## Proposed Mediarama customization layers

### Theme

Presentation only:

- Twig component/template overrides;
- design tokens;
- UIkit variable/custom.less layer;
- static assets;
- component variants.

### Extension

Application capability:

- typed events;
- commands/handlers;
- navigation contributions;
- metadata processors;
- authorization extensions;
- import adapters;
- explicit UI slots.

### Site configuration

Operator policy/content:

- enabled components;
- layout choices exposed safely;
- branding;
- navigation arrangement;
- feature toggles where product-defined.

This prevents a theme from becoming an application fork.

## Migration expectation

Coppermine themes should not be automatically ported.

Migration preflight should report the active theme and custom theme directories because:

- custom PHP may contain site-specific behavior;
- custom assets/branding may need manual recreation;
- a theme may contain code that is effectively a plugin/customization.

The migration report should classify:

- stock known theme;
- modified stock theme;
- unknown/custom theme.

Automatic theme-code execution/import is out of scope.

## Branch-comparison conclusion

For theme architecture:

- 1.6 and ordinary 1.7 retain the same major override function set;
- 1.7 adds Theme2/responsive experiments;
- no branch provides the strict presentation boundary Mediarama requires.

The useful outcome to preserve is **deep visual customizability**, not PHP-function replacement.

## Conclusion

Coppermine's theme system is one of its strengths and one of its largest coupling points.

Mediarama should be at least as customizable visually, but customization should happen through explicit Twig/UIkit component boundaries rather than replacing globally coupled PHP rendering functions.
