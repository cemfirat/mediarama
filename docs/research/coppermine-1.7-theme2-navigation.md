# Coppermine 1.7 Theme2 and responsive navigation audit

Status: **verified first-pass 1.7 UX experiment audit**
Date: 2026-09-25
Tracking: #11

Primary source:

- `include/themes2.inc.php`
- `css/theme2.css`
- `js/tabnav.js`
- `themes/curve2/*`
- `themes/water_drop2/*`
- 1.7 `displayimage.php` / `thumbnails.php`

## What Theme2 actually is

Theme2 is **not** a new application/presentation architecture.

It remains inside Coppermine's existing theme-function/template model and still contains substantial legacy HTML/table-based fragments.

Its value is narrower and practical:

- responsive layout experiments;
- CSS Grid/Flex adoption in selected areas;
- hamburger/admin-menu behavior;
- keyboard and touch/pointer navigation;
- revised image/thumbnail navigation;
- a path toward less table-dependent layout.

This distinction matters for Mediarama: preserve the UX ideas, not the architecture.

## Responsive CSS improvements

`theme2.css` introduces modern layout primitives in selected areas.

Confirmed examples include:

- Flexbox for admin/user menus;
- flex-wrapped category/album areas;
- flex-wrapped image-navigation bars;
- CSS Grid for thumbnail cells;
- responsive media query at 767px;
- hamburger menu visibility on small screens;
- collapsed/stashed menu states;
- `touch-action: pan-y` for thumbnail grid interaction.

The thumbnail grid uses an auto-filling grid with constrained cell widths instead of relying exclusively on table columns.

## Mobile navigation

Theme2 has explicit burger-menu templates and mobile menu behavior.

At small widths:

- system navigation becomes a vertical menu;
- icons can be hidden;
- admin/user menus become positioned collapsible panels;
- menu wrappers use a "stashed" collapsed state.

This confirms that 1.7 recognized responsive navigation as a core UX problem.

## Keyboard and swipe navigation

`js/tabnav.js` adds keyboard/touch/pointer behavior.

### Thumbnail/result pagination

For supported thumbnail views:

- Arrow Right → next page
- Arrow Left → previous page
- Arrow Up → first page
- Arrow Down → last page
- horizontal swipe over 100px → previous/next page

### Media viewer

For the individual media view:

- Arrow Right → next media
- Arrow Left → previous media
- Arrow Up → first media
- Arrow Down → last media
- horizontal swipe over 100px → previous/next media

Pointer Events are preferred when available, with touch-event fallback.

## Quality limitations visible in the experiment

The code also shows why it should be treated as research rather than copied.

Examples:

- global document-level keyboard listeners;
- hard-coded navigation behavior;
- multiple `console.log()` debug calls;
- direct DOM click/navigation;
- page-specific URLs hard-coded in the tab navigator;
- limited accessibility semantics around gestures;
- no clear focus/input-field guard before reacting to arrow keys;
- existing legacy theme/table markup remains around the new responsive pieces.

## Mediarama viewer requirements derived from this

Mediarama should retain/improve the interaction outcomes:

- previous/next navigation;
- first/last where useful;
- keyboard navigation;
- touch/pointer swipe on the media stage;
- visible controls;
- filmstrip/thumbnail navigation as an optional component;
- responsive mobile controls;
- no reliance on hover;
- accessible focus and button semantics.

But Mediarama should add stricter behavior:

- do not hijack arrow keys while focus is in forms/editors;
- honor reduced-motion preferences;
- make gesture thresholds/configuration testable;
- support screen-reader labels/state;
- preserve browser history/deep URLs;
- separate viewer state from raw DOM navigation;
- use UIkit components only where they improve semantics, not to mimic Coppermine markup.

## Theme separation lesson

Even Theme2 still demonstrates a recurring Coppermine limitation:

> responsive improvements are implemented inside a presentation system that can contain application decisions and duplicated theme PHP.

Mediarama should keep:

`Controller / Query / Authorization → View Model → Twig/UIkit components`

Theme customization should not require copying authorization, SQL or media-navigation business logic.

## 1.7 value to preserve

The following 1.7 ideas are worth carrying forward as product requirements:

- responsive-first thumbnail layouts;
- responsive/collapsible administration navigation;
- touch/pointer gallery navigation;
- keyboard gallery navigation;
- mobile-friendly media controls;
- clearer separation of pagination tabs/navigation.

They do **not** justify using 1.7 as a code foundation.
