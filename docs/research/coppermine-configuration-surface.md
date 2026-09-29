# Coppermine configuration-surface audit

Status: **verified structural inventory**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current 1.6 `include/admin.inc.php`
- current 1.6 `sql/basic.sql`
- 1.7 equivalents for comparison

## Visible 1.6 administrator configuration

The current 1.6 admin configuration exposes **199 settings** across 19 sections.

| Section | Keys |
| --- | ---: |
| General settings | 16 |
| Language/charset | 3 |
| Themes | 11 |
| Album list view | 13 |
| Thumbnail view | 15 |
| Image view | 15 |
| Comments | 19 |
| Contact form | 7 |
| Thumbnail generation | 8 |
| File/media settings | 25 |
| Watermarking | 9 |
| Registration | 8 |
| User settings | 21 |
| User profile custom fields | 6 |
| Image-description custom fields | 4 |
| Cookies | 2 |
| Email/SMTP | 4 |
| Logging/statistics | 8 |
| Maintenance | 5 |

This breadth is itself a product lesson: a mature self-hosted gallery accumulates many operator policies beyond the public gallery view.

## Current 1.6 base configuration count

`sql/basic.sql` seeds **221** configuration keys.

Therefore not every runtime key is directly represented in the ordinary admin configuration UI.

## 1.6 seeded keys outside the main admin form

Current 1.6 base config includes the following keys that are not direct 4-space admin-form entries in `include/admin.inc.php`:

- `allow_guests_enter_file_details`
- `auto_orient_checked`
- `bridge_enable`
- `comment_akismet_counter`
- `comment_email_notification`
- `cookies_need_consent`
- `display_admin_uploader`
- `enable_encrypted_passwords`
- `guest_token_cleanup`
- `keep_votes_time`
- `last_updates_check`
- `orig_pfx`
- `performance_page_generation_time`
- `performance_page_query_count`
- `performance_page_query_time`
- `performance_timestamp`
- `randpos_interval`
- `session_cleanup`
- `show_which_exif`
- `site_token`
- `theme_list`
- `upload_h5a`

Some are hidden/internal state, some are legacy/compatibility switches, some are manipulated by specialized managers/plugins, and some are historical migration/config keys.

They must be classified individually rather than treated as user-facing product requirements.

## 1.6 vs 1.7 configuration counts

Using the same parser against both branches:

- current 1.6 base config: **221**
- 1.7 base config: **217**
- current 1.6 visible admin keys: **199**
- 1.7 visible admin keys: **198**

### 1.7 adds

- `thumbs_per`

### Current 1.6 base config keys absent from 1.7

- `comment_email_notification`
- `display_admin_uploader`
- `display_sidebar_guest`
- `display_sidebar_user`
- `randpos_interval`

### Visible admin differences

1.7 adds:

- `thumbs_per`

1.7 removes from the visible admin form:

- `display_sidebar_guest`
- `display_sidebar_user`

These deltas require behavioral/commit-history interpretation before they are treated as product removals.

## Important policy families

### Gallery/general

Includes:

- gallery name/description;
- canonical gallery URL;
- home target;
- ZIP download;
- help;
- keywords;
- plugin system;
- batch-add behavior;
- CSRF/form-token lifetime.

### Presentation

Includes:

- theme selection;
- custom link/header/footer;
- date browsing;
- album/page/grid counts;
- captions/filename/uploader/views/rating display;
- filmstrip/slideshow;
- menu/sidebar behavior.

Mediarama should not inherit layout-oriented legacy keys literally. The user outcomes should be expressed through modern theme/UI configuration.

### Media ingestion and processing

Includes:

- max upload byte size;
- maximum dimensions;
- automatic resizing;
- allowed image/movie/audio/document extensions;
- graphics backend;
- ImageMagick path/options;
- JPEG quality;
- EXIF/IPTC reading;
- storage directories/modes;
- intermediate image generation.

Mediarama already replaces many of these with stronger processing/storage abstractions.

### Watermarking

Coppermine exposes:

- enablement;
- thumbnail watermarking;
- placement;
- which files receive watermark;
- watermark file;
- transparency;
- reduction/feather settings.

Mediarama should treat watermarking as a derivative transform with explicit versioned profiles.

### Identity and registration

Includes:

- self-registration;
- shared/global registration password;
- disclaimer;
- CAPTCHA;
- email verification;
- admin activation;
- personal album creation;
- anonymous access;
- login identifier mode;
- brute-force thresholds/expiry;
- ban cleanup;
- member list;
- email/account self-service;
- own-media and own-album permissions.

### Interaction/moderation

Includes:

- comment length/line/flood rules;
- approval policy;
- pending placeholders;
- edit policy;
- CAPTCHA;
- Akismet;
- notification;
- report-to-admin;
- rating-own-files policy.

### Logging/statistics

Includes:

- global/admin logging;
- e-card logging;
- detailed votes;
- detailed hits;
- index statistics;
- media/album/admin hit counting.

Mediarama should retain observability and useful aggregate statistics while deliberately reducing historical network/client tracking.

## Migration rule for configuration

Mediarama must **not** import the 221-key config table wholesale.

Every source key should be classified into one of:

1. map to an equivalent Mediarama setting;
2. transform into a different policy/model;
3. use only during migration;
4. intentionally ignore as legacy implementation detail;
5. security-sensitive secret/state that must never be copied;
6. unresolved blocking gap.

Examples of values that should not simply transfer:

- SMTP password;
- Akismet API key;
- old cookies/tokens;
- legacy theme names/paths;
- ImageMagick executable paths;
- old filesystem permissions;
- session cleanup state;
- source plugin internals.

## Preflight recommendation

The Coppermine migration preflight should eventually report at least:

- source config deviations from defaults;
- settings with known Mediarama mappings;
- settings that imply a migration behavior (bridge, user galleries, approval, custom file types);
- unknown/unsupported high-impact settings;
- secrets/state that will explicitly not be copied.

This is preferable to silently ignoring a heavily customized installation.

## Explicit migration allowlist

Mediarama does **not** copy the Coppermine `config` table into target configuration.

Only source values with a dedicated migration interpretation are allowed to influence the importer:

| Source key | Migration use | Target treatment |
| --- | --- | --- |
| `bridge_enable` | determine whether local Coppermine users are authoritative | enabled bridging blocks migration |
| `allow_private_albums` | interpret effective album privacy | conflicting disabled/private state blocks migration |
| `keyword_separator` | split source picture keywords correctly | used only during tag import; Coppermine's stored `%20` value is decoded to a space |
| `old_style_rating` | interpret detailed source rating scale | used only during rating normalization |
| `rating_stars_amount` | interpret detailed source rating scale | validated against Coppermine's documented 1..20 range and normalized to Mediarama's five-star model |
| `lang` | validate the source installation's default language identity | not copied as a Mediarama runtime setting |

Language data is handled separately through the source `languages` table and `users.user_language`:

- `user_language` is a Coppermine language ID such as `english` or `german`;
- the importer resolves it through `languages.lang_id`;
- `languages.abbr` becomes the Mediarama user locale, for example `english → en` and `german → de`;
- missing/unavailable language definitions or empty abbreviations block migration rather than storing a Coppermine filename-style language ID as a target locale.

No other Coppermine config key is imported automatically. Presentation, theme, SMTP, cookie/session, executable paths, filesystem modes, plugin/runtime switches, language autodetection and similar source-runtime settings remain Mediarama product/operations configuration unless a future dedicated migration rule explicitly adds them to this allowlist.

