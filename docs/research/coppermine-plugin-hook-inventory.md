# Coppermine plugin hook inventory

Status: **verified source + bundled visible-hook inventory**
Date: 2026-09-25
Tracking: #11

Primary sources:

- direct searches for `CPGPluginAPI::action` and `CPGPluginAPI::filter`;
- bundled `plugins/visiblehookpoints/codebase.php` in both branches;
- `include/plugin_api.inc.php`.

The direct-call list below is intentionally narrower than the complete extension surface because many hooks are emitted through theme/helper code or are not visible through a single literal search.

## 1.6 action hooks observed

- `add_file_data_success`
- `after_delete_file`
- `after_edit_file`
- `authorize_user`
- `before_delete_file`
- `captcha_comment_validate`
- `captcha_contact_validate`
- `captcha_ecard_validate`
- `comment_approve`
- `comment_update`
- `page_start`
- `plugin_cleanup`
- `plugin_configure`
- `plugin_wakeup`
- `post_breadcrumb`
- `profile_display_form`
- `profile_submit_form`
- `register_form_submit`
- `register_user_activation`
- `theme_thumbnails_wrapper_end`
- `theme_thumbnails_wrapper_start`
- `upload_form`
- `upload_process`

## 1.6 filter hooks observed

- `add_file_data`
- `captcha_contact_print`
- `captcha_ecard_print`
- `captcha_register_print`
- `cpg_mail_sender_email`
- `cpg_mail_to_email`
- `file_info`
- `html_image_reduced_overlay`
- `image_sharpen`
- `ip_information`
- `javascript_includes`
- `main_page_layout`
- `page_html`
- `page_meta`
- `profile_add_data`
- `register_form_create`
- `search_form`
- `smilies_display`
- `smilies_process`
- `sub_menu`
- `sys_menu`
- `theme_name`
- `theme_pageheader_params`
- `thumb_caption_lastupby`
- `upload_file_name`
- `upload_options`
- `user_caption_params`
- `usermgr_footer`
- `usermgr_header`

## Correction: bundled hook registry is the same in both branches

The earlier literal-search pass made `replace_forbidden_conditions`, `theme_thumbnails_footer` and `token_criteria` look 1.7-only because the current 1.6 call sites are located differently.

The bundled `visiblehookpoints` plugin provides a stronger cross-check.

Its declared action-hook list and filter-hook list are byte-for-byte equivalent in the checked current 1.6 and 1.7 files.

That shared filter inventory explicitly includes:

- `replace_forbidden_conditions`
- `theme_thumbnails_footer`
- `token_criteria`

Therefore these are **not 1.7-only hook concepts**.

This correction is important: 1.7 did not introduce a materially new plugin-hook surface in the checked bundled registry.

## Hook coverage by product area

The hook names confirm extension points around:

- media/file creation and deletion;
- upload forms/options/processing;
- image processing/sharpening;
- media/file info;
- registration and activation;
- profile forms/data;
- authorization;
- comments/moderation;
- captcha;
- e-cards/contact;
- mail routing/sender;
- search form;
- menus/layout/page HTML/meta;
- JavaScript inclusion;
- themes and thumbnail wrappers;
- user manager UI;
- plugin lifecycle.

This breadth is an important Coppermine strength: plugins can affect more than presentation.

## Mediarama implication

The goal is **not** source compatibility with these hook names.

Mediarama should preserve the underlying extensibility outcomes through:

- typed domain/application events;
- explicit extension contracts;
- UI slots/components;
- authorization-policy extension points;
- media-inspection/processing extension points;
- controlled metadata normalization hooks;
- lifecycle hooks with documented ordering and failure semantics.

Hooks that can arbitrarily rewrite raw HTML/global state should not be reproduced by default.

## Remaining hook audit

Before plugin architecture can be considered fully researched:

- inspect `include/plugin_api.inc.php` lifecycle and priority semantics;
- inspect plugin install/config/uninstall persistence;
- inspect sample plugin(s) end to end;
- identify whether hooks can stop/default behavior or only transform data;
- map parameter/return contracts for every high-value hook;
- identify dynamically constructed hook names not caught by source search or the bundled visible-hook plugin;
- [x] compare the bundled visible-hook registry across 1.6 and 1.7;
- [x] compare 1.6 vs 1.7 plugin-manager behavior;
- classify which Coppermine plugin capabilities deserve first-class Mediarama extension APIs.


## Plugin lifecycle and ordering semantics

The first source-level lifecycle pass confirms several important behaviors.

### Persistent registry

The core `plugins` table stores:

- plugin ID
- name
- enabled flag
- path
- integer priority

Installed plugins are loaded ordered by `priority`.

The plugin manager can move plugins up/down, making hook execution order an administrator-controlled property.

### Enable/disable

Plugins can remain installed while disabled.

Disabled plugins are skipped for normal filter/action dispatch, but uninstall code can still be loaded so cleanup can run.

The entire plugin system can also be disabled globally through configuration.

### Wakeup

Plugin loading has a wakeup phase. A plugin that does not successfully wake is skipped by ordinary actions/filters.

This means Coppermine's plugin model has an explicit runtime activation concept beyond merely "file exists".

### Filters

Filters are sequential transformations.

For each enabled/awake plugin that registered the named filter:

1. the current value is passed to the plugin callback;
2. the callback return value becomes the value passed to the next plugin;
3. final transformed value is returned to core.

Therefore plugin priority can materially change behavior.

### Actions

Actions use the same ordered plugin traversal model but represent lifecycle/side-effect extension points.

The API also supports scoped execution for a specific/new plugin, used during lifecycle operations.

### Install/uninstall

Installation:

- chooses a priority after existing plugins;
- loads plugin code/config metadata;
- invokes `plugin_install`;
- can return an integer to indicate additional configuration is required;
- persists registry information only after successful install.

Uninstall:

- invokes `plugin_uninstall`;
- can return a numeric state indicating cleanup is still required;
- removes registry row on success;
- compacts priorities.

### Plugin manager capabilities

The admin plugin manager supports:

- global plugin-system enable/disable;
- discovery of plugin directories;
- version compatibility metadata;
- install;
- uninstall;
- enable/disable;
- priority reordering;
- plugin-specific configuration/admin UI;
- plugin package upload/delete.

## Mediarama extension-design consequence

Mediarama should preserve:

- deterministic extension ordering where ordering is meaningful;
- explicit enable/disable state;
- install/upgrade/uninstall lifecycle;
- extension-specific configuration;
- typed transformation pipelines;
- lifecycle failure handling.

It should **not** expose arbitrary global PHP state or unrestricted raw-page rewriting as the default extension contract.

A future extension system should also define:

- transaction boundaries;
- asynchronous vs synchronous handlers;
- timeout/failure isolation;
- versioned API contracts;
- capability permissions/sandbox expectations;
- extension-owned schema migration/uninstall policy;
- how plugin-owned data participates in backup/migration.

The last point is directly relevant to Coppermine migration: unknown installed plugins can own data outside the 22 core tables.


## 1.6 vs 1.7 Plugin API implementation delta

A normalized comparison of `include/plugin_api.inc.php` shows that the core implementation is also very close.

The visible 1.7 changes are primarily PHP-modernization syntax, for example:

- short array syntax;
- `#[AllowDynamicProperties]`;
- compact property initialization.

The core lifecycle/order model remains the same.

This supports the conclusion that 1.7 does not provide a redesigned extension architecture for Mediarama to inherit.


## Plugin manager/sample branch comparison

The following checked files are byte-identical between current 1.6 and 1.7:

- `pluginmgr.php`
- `plugins/sample/codebase.php`
- `plugins/sample/configuration.php`

Therefore the ordinary plugin-manager workflow and bundled sample plugin do not represent a 1.7 architectural evolution.

For Mediarama, the extension-system design should be based on the useful outcomes documented above, not on an assumption that 1.7 contains a newer plugin model.
