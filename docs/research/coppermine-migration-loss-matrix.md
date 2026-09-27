# Coppermine → Mediarama migration loss matrix

Status: **provisional — intentional omissions classified; unresolved blockers remain**
Date: 2026-09-26
Tracking: #8, #11

This matrix exists to prevent the importer from being declared complete merely because albums, pictures, users, comments and ratings migrate successfully.

The current Coppermine 1.6/1.7 core schema contains **22 core tables**, plus plugin-owned data may exist outside the core schema.

Each source concept must end in one of these states:

- **Direct** — preserve essentially equivalent data/semantics;
- **Transform** — preserve meaning in a different Mediarama model;
- **Historical** — preserve only aggregate/audit/history where individual semantics cannot or should not be reconstructed;
- **Intentional omission** — explicitly do not migrate, with rationale;
- **Blocking gap** — unresolved; architecture/import cannot be signed off yet.

## Core schema matrix

| Coppermine source | Meaning | Current Mediarama status | Classification | Remaining work |
| --- | --- | --- | --- | --- |
| `albums` | album metadata, ownership, visibility, password settings, explicit cover, aggregate views, per-album upload/comment/rating policy | importer preserves structure/ACL/password intent, positive explicit cover PIDs, `alb_hits` as collection `view_count`, and combines album switches with group capabilities into collection-scoped ACL rules | Transform | real-gallery validation remains |
| `banned` | user/name/email/IP bans, expiry, brute-force flag | non-empty table is rejected by preflight | Unsupported / block | do not silently drop security state; migrate only after a Mediarama ban model is deliberately designed |
| `bridge` | external-application bridge configuration | enabled bridging is rejected by preflight | Unsupported / block | local Coppermine users are not treated as authoritative while bridging is enabled |
| `categories` | hierarchy, ownership, structural grouping, explicit cover | importer preserves hierarchy/ownership and positive explicit cover PIDs | Transform | real-gallery tests remain |
| `categorymap` | groups allowed to create albums in categories | imported to category collection `collection.create_child` ACL entries | Transform | real-gallery permission validation remains |
| `comments` | comments, author, moderation/spam | registered/guest authors, body, timestamp and moderation/spam state migrate; historical IP is deliberately excluded | Transform + privacy reduction | real-gallery validation remains |
| `config` | large installation-wide behavior/config surface | explicit migration allowlist only; no bulk copy | Transform + intentional config omission | dedicated rules cover bridge/private-album semantics, keyword parsing, rating normalization and source-language validation; all other keys remain target product/operations policy unless explicitly added |
| `dict` | derived keyword dictionary | not migrated; source picture keywords are normalized into tags/media_tags | Intentional omission / rebuild | none: Coppermine itself rebuilds this table from `pictures.keywords` |
| `ecards` | optional sent-e-card log with sender/recipient identity/contact data, IP and encoded payload | not migrated into Mediarama product storage | Intentional omission | separate restricted archive/export only if a specific installation has a retention obligation |
| `exif` | serialized cache of selected EXIF values derived from the picture file | not migrated as authoritative data; Mediarama re-extracts the original file with ExifTool | Intentional cache rebuild / Transform | real-gallery validation only |
| `favpics` | authenticated-user favorites serialized in source | decoded for mapped users/pictures and imported to normalized favorites | Transform | anonymous browser-local favorites remain an intentional limitation |
| `filetypes` | extension→MIME→content type→player registry | not migrated as runtime policy; actual source files are content-inspected and unsupported real MIME/decoder states block preflight | Intentional config omission + content transform | registry remains audit evidence only; target support is determined from real bytes and Mediarama policy |
| `hit_stats` | detailed views incl. IP, search phrase, referrer, browser, OS, user | raw rows are intentionally not migrated; aggregate `pictures.hits` / `albums.alb_hits` are preserved separately as `view_count` | Intentional omission of raw telemetry + Direct aggregate preservation | retain product counters without copying privacy-heavy network/client history |
| `languages` | installed/available/enabled language definitions | required as migration lookup; user language IDs map through `abbr` to target locale | Transform | runtime language files, autodetection/enabled-list behavior and display labels are not copied as target configuration |
| `pictures` | media records, metadata, ownership, approval, counters, custom fields, user-gallery icon, source-root selector | core media import preserves aggregate `hits` as media `view_count`; non-empty `user1..4`, non-zero `galleryicon` and non-zero `url_prefix` block preflight | Transform + explicit blockers | design target custom-field/user-gallery representation models; multi-root source addressing requires an explicit source resolver |
| `plugins` | installed plugin registry, enablement and priority; plugin/custom tables may remain independently | registry itself is not migrated; installed rows and unknown Coppermine-prefixed tables block preflight | Intentional omission as runtime registry + plugin/custom-data blocker | audit/resolve each installed plugin and any unknown prefixed table before core migration |
| `sessions` | active login/remember-me runtime state | not migrated | Intentional omission | legacy authentication/session credentials expire with Coppermine; users establish new Mediarama auth state |
| `temp_messages` | transient redirect/cross-page status messages | not migrated | Intentional omission | deleted after fetch/cleanup; request-flow state, not content |
| `usergroups` | global capabilities, quotas, upload approval, access tier, e-card capability | major capabilities and group quotas migrate; upload-approval/access-tier semantics still block preflight until equivalent target policy exists | Transform + explicit blockers | quota policy is normalized in `group_storage_quotas`; deliberate moderation/derivative-access policy is still required for the remaining blockers; e-card capability is intentionally omitted with the feature |
| `users` | local identities, profiles, activation/status | core identity import exists; non-empty `user_profile1..6` now blocks preflight | Transform + explicit blockers | design profile-field target mapping; activation tokens are intentionally not reusable; bridged identities remain unsupported |
| `votes` | per-voter anti-repeat records without the individual rating value | individual rating is not fabricated; source aggregate remains preserved | Historical / intentional omission of unrecoverable detail | none unless separate recoverable detailed rating data exists |
| `vote_stats` | detailed ratings + IP/referrer/browser/OS/user | recoverable user-linked rating value/time migrate; network/client telemetry does not | Transform + intentional privacy reduction | no raw telemetry import by default |

## Important non-table behavior/data

### Anonymous favorites

Coppermine can persist favorites in a client cookie as a serialized/base64-encoded PID list.

These are not part of the server-side `favpics` table for an anonymous visitor. A server migration cannot reliably transfer a browser-local favorite list without a separate client-side migration mechanism.

Current classification: **intentional limitation unless a practical opt-in migration workflow is designed**.

### Per-album upload/comment/rating policy

Coppermine's `albums.uploads`, `albums.comments` and `albums.votes` are active runtime policy, not presentation metadata.

Verified source behavior combines each album switch with the user's/group's corresponding global capability.

Mediarama maps this into its native resource ACL model:

- source upload capability + `uploads=YES` → `collection.media.add`;
- source comment capability + `comments=YES` → `media.comment`;
- source rating capability + `votes=YES` → `media.rate`.

No rule is created when the album switch is disabled.

This avoids both failure modes:

- silently dropping a restrictive album policy;
- adding Coppermine-only boolean fields that duplicate Mediarama authorization state.

CI includes an enabled album and a disabled album. It verifies the ACL rows and exercises the actual collection upload-permission repository for a non-owner user.

Classification: **Transform**.

## Album/category keyword semantics

Coppermine can associate an album keyword with media whose picture keyword string matches that value, meaning some album-like membership can be dynamic rather than represented solely by `pictures.aid`.

The importer now materializes those source semantics as additional normalized `collection_media` links after native `aid` membership is imported. CI verifies a picture can belong to its native album and an album-keyword-linked collection without moving or duplicating the MediaAsset.

Classification: **Transform**.

### Plugin-owned data

The core `plugins` table stores only plugin registry information (name/path/enabled/priority).

Installed plugins can execute install/uninstall/configuration code and may maintain their own files, configuration or database tables. Therefore:

> A core-schema-only importer can never claim arbitrary Coppermine installation completeness without first inventorying installed plugins.

Required migration behavior:

1. enumerate installed plugins;
2. inventory the audited Coppermine core-table set;
3. detect extra tables using the configured Coppermine prefix even when no plugin registry row remains;
4. identify plugin/custom persisted data;
5. classify each extension as supported migration / custom migration / intentional omission / blocker;
6. block before writes while unknown prefixed tables or installed plugins remain unresolved.

Mediarama now implements both database-level checks: a non-empty plugin registry blocks, and any prefixed table outside the audited 1.6/1.7 core table set blocks. This does not prove that an extension never stored data under another prefix or outside the database; real-installation validation still needs the operator to review extension/customization history.

### Coppermine EXIF cache

The `exif` table is not an independent metadata authority.

Verified in both 1.6 and 1.7:

- when no row exists, Coppermine reads EXIF from the image file;
- it strips the parsed result to configured fields and serializes that derived array into `exif.exifData`;
- when picture metadata is refreshed/edited, Coppermine deletes the cached row so it will be regenerated from the file on the next read.

Migration policy: **rebuild from the original media file**.

Mediarama already runs ExifTool against the migrated original and preserves a richer group-qualified EXIF/IPTC/XMP snapshot plus normalized canonical metadata. Importing the serialized Coppermine cache would add stale-data risk and would lose fields Coppermine had not configured for display.

CI now includes an intentionally stale `cpg_exif` cache row whose camera make disagrees with the source JPEG. The target must keep the value re-extracted from the actual file, proving the cache is not treated as authoritative.

Classification: **Intentional cache rebuild / Transform**.

## Custom file types

Coppermine's `filetypes` registry is not just a hard-coded list.

Current 1.6 defaults contain **107 extensions**:

- 13 image
- 11 movie
- 7 audio
- 76 document

1.7 adds three defaults:

- `webp`
- `webm`
- `weba`

The table is site data/configuration, so an installation can differ from defaults.

Mediarama must not infer import support merely from filename extension. It should continue content inspection, but the migration audit must preserve awareness of source custom file-type policy and unsupported media.

## Category creation rights

`categorymap` is actively used in Coppermine to determine which groups may create/manage albums in specific categories. This is semantically different from album visibility.

The importer now maps these rows to collection-scoped `collection.create_child` allow rules, with CI coverage for the source category/group mapping.

Classification: **Transform**. Real-gallery permission validation remains, but this is no longer an unimplemented migration gap.

## Configuration and language allowlist

The migration does not reproduce Coppermine's installation-wide config table.

Only explicitly referenced source settings may influence interpretation:

- `bridge_enable`;
- `allow_private_albums`;
- `keyword_separator`;
- `old_style_rating`;
- `rating_stars_amount`;
- `lang` for source-language validation.

`users.user_language` is data, not a raw target config value. It is resolved through `languages.lang_id` to `languages.abbr` before being stored as the Mediarama user locale.

This also fixes a source-specific encoding rule: Coppermine stores a space keyword separator as `%20`; migration decodes it before splitting keywords.

Everything else in the source configuration remains non-portable runtime/product policy unless a dedicated rule is added later. This prevents SMTP credentials, cookie/session settings, theme names, executable paths, old filesystem modes or UI-layout switches from leaking into Mediarama configuration.

## User-group policy semantics

The importer maps the major reusable global capabilities, but Coppermine also derives runtime policy across all of a user's group memberships.

Verified 1.6/1.7 behavior:

- ordinary capabilities are combined permissively;
- quota is unlimited if any membership has `group_quota=0`, otherwise the largest quota wins;
- public/private approval flags use the least restrictive value;
- `access_level` uses the highest level: `0` none, `1` thumbnail only, `2` intermediate, `3` full-size.

Mediarama now has persistent normalized upload quota policy. Coppermine `group_quota` values migrate from KiB to bytes into `group_storage_quotas`, with `0` remaining explicit unlimited, and runtime resolution preserves the audited multi-group rule. Source-style public/private upload approval and derivative/full-size access tiers are still not modeled.

Migration policy remains: **evaluate effective policy per existing user and fail closed when current Mediarama behavior would be more permissive or would discard a restriction**.

Preflight therefore blocks:

- required public-upload approval when the user can upload/create albums;
- required private/user-gallery upload approval when the user can upload/create albums;
- effective access level below full-size;
- missing referenced groups.

Valid finite quotas no longer block migration; negative quota values remain invalid source state and are rejected.

This is intentionally not a raw per-group blocker: a supplemental group that makes a user's effective Coppermine policy unlimited/no-approval/full-access is respected exactly as Coppermine would calculate it.

`can_send_ecards` is classified differently. Mediarama does not carry forward the e-card product feature, so its group capability is an **intentional product omission** rather than a migration blocker. Historical e-card records remain a separate privacy/data-retention decision.

## Historical view counters vs detailed telemetry

Coppermine maintains two different layers of view data:

- `pictures.hits` and `albums.alb_hits` are user-visible aggregate counters;
- `hit_stats` can contain per-hit timestamp, IP, referrer, search phrase, browser, OS and user ID.

Mediarama preserves the aggregate counters as non-negative `view_count` fields on media and collections. This retains product-visible popularity/history without requiring the privacy-heavy raw event log.

Negative legacy counter values are treated as corrupt source state and block preflight before target writes.

The detailed `hit_stats` rows are an **intentional omission** from the core migration. Analytics/audit retention can be designed separately if there is a concrete legal or product requirement; it is not copied merely for Coppermine parity.

## Privacy-sensitive historical data

The final omission policy is centralized in `docs/research/coppermine-intentional-omissions.md`.

In summary:

- aggregate media/album views are preserved, raw `hit_stats` telemetry is not;
- recoverable rating values/identity are preserved, vote client/network telemetry is not;
- comments retain content/author/time/moderation, not historical IP addresses;
- picture upload/last-hit IP fields are not migrated;
- e-card correspondence/log data is not imported into the Mediarama product database;
- bans are **not** treated as an omission: a non-empty ban table blocks migration because it is active security state.



## Unmodeled user-defined and source-address data

Several fields are genuine installation/user data rather than harmless Coppermine implementation residue:

- `pictures.user1..4` are administrator-labelled custom fields exposed in media edit/upload forms;
- `users.user_profile1..6` are configurable user profile fields;
- `pictures.galleryicon` identifies a selected picture used to represent a user's Coppermine gallery;
- `pictures.url_prefix` participates in Coppermine's multi-server/source-root file addressing.

The current Mediarama model has no equivalent profile/custom-field or user-gallery-icon target, and the importer has one explicit local albums root rather than Coppermine's historical URL-prefix routing.

Migration policy: **unsupported-and-block until a deliberate target mapping exists**.

Preflight reports source IDs and populated field names only. It does not print the field values. Any non-empty custom/profile field, non-zero `galleryicon`, or non-zero `url_prefix` stops migration before writes so these values cannot disappear silently or cause the importer to read the wrong source object.

### Source security tokens

Coppermine activation keys and browser/guest unlock tokens are credentials for the old runtime, not portable business data. They are deliberately not reused as Mediarama credentials. User identities already require Mediarama password reset/re-establishment; source activation/unlock tokens must expire with the source application rather than being copied forward.

## Explicit collection-cover migration

Coppermine stores user-selected album/category thumbnails as positive picture IDs. These are stable source choices and map directly to Mediarama's nullable `collections.cover_media_id`.

Migration rules:

- positive `albums.thumb` / `categories.thumb` values must resolve to a source picture during preflight;
- after media import, the corresponding picture mapping becomes the target collection cover;
- non-positive values remain unpinned because Coppermine uses them for automatic/dynamic behavior such as “last uploaded” or random album thumbnails;
- Mediarama does not freeze a runtime-random source choice into permanent migrated data.

## Intentional omission policy

Every deliberate omission/reduction currently identified by the migration audit is listed with primary-source rationale in `docs/research/coppermine-intentional-omissions.md`.

This includes derived dictionaries, sessions, temporary messages, e-cards, detailed hit telemetry, comment/picture network identifiers, vote client telemetry/unrecoverable detail, anonymous browser-local favorites, legacy security tokens, the Coppermine plugin registry as target runtime state, and dynamic cover selection.

A new omission must be added to that document and this matrix before it can be considered deliberate rather than accidental.

## Exit condition

Issue #8 cannot be treated as migration-complete until every row in this matrix has a final classification and all **Blocking gap** entries are resolved or explicitly accepted as unsupported with preflight failure/warning behavior.


## Album visibility/password-specific migration risks

The deeper access-control audit adds several migration rules that are not represented by the basic albums table mapping alone.

### Visibility integer must be decoded, not copied

Coppermine `albums.visibility` mixes principal types:

- `0` public;
- ordinary group IDs;
- `FIRST_USER_CAT + user_id` user/private visibility.

Mediarama must transform these to explicit resource ACLs and fail closed when a principal cannot be mapped.

### Password-bearing albums

A source album password is an alternate access mechanism for an otherwise restricted album.

Migration classification: **Transform**.

Required target behavior:

- preserve the fact that password protection existed;
- preserve/review the hint;
- do not reuse the MD5 source password hash;
- do not import browser unlock cookies;
- keep the target restricted until a new password/policy is set.

The existing ACL importer already follows this direction by requiring a password reset/replacement.

### Global private-album switch

`allow_private_albums` changes effective runtime behavior. Coppermine only builds/enforces the private-album forbidden set when this switch is enabled; its own administration text also warns that disabling the switch makes existing private albums visible.

Migration policy: **fail closed on conflicting stored intent**.

Preflight now requires `allow_private_albums` to be present with value `0` or `1`. When it is `0`, migration is blocked if any album still carries non-zero `visibility` or an album password. That state has two competing truths — effective Coppermine behavior was public, while stored album metadata still expresses restricted intent — so Mediarama does not guess which one should win.

### Moderator-group residue

`albums.moderator_group` remains in schema/runtime code, but current 1.6 disables normal persistence of the feature and the updater resets values to zero.

Migration classification: **Blocking review when non-zero**.

Preflight must count non-zero rows. A non-zero value may indicate:

- historical residue;
- an old installation not fully updated;
- plugin/custom code that re-enabled the feature.

Do not grant Mediarama moderator rights from this field automatically.

## Identity preflight gap: duplicate emails

Coppermine can allow duplicate email addresses.

Mediarama's current users table enforces unique non-null `CITEXT` email.

Current identity import therefore has a production migration blocker for source galleries containing duplicate non-empty emails.

Required preflight:

- duplicate normalized email count;
- invalid emails;
- empty emails;
- duplicate-email policy decision before insert.

Migration must not silently rewrite user emails without a reportable rule.


## Explicit preflight policy for security and extensions

### Ban table

Coppermine's `banned` table is active security state, not harmless history. It can represent user, name, email or IP bans with expiry plus brute-force lockout records.

Mediarama currently has no equivalent ban/expiry model. A non-empty source table therefore blocks migration before identity writes. Preflight reports only counts by manual/brute-force type and does not echo source email/IP values into logs.

This is an **unsupported-and-block** classification, not an intentional discard.

### Installed plugins

The core `plugins` table records plugin name/path/enabled state/priority, but Coppermine plugins can maintain separate tables, files and configuration. Persisted data can remain after a plugin is disabled.

The registry itself is not imported into Mediarama runtime state. Any installed plugin row or unknown Coppermine-prefixed table blocks core migration until the extension/custom data has been audited and resolved.
