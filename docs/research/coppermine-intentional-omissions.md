# Coppermine intentional migration omissions

Status: **explicit migration policy**
Date: 2026-09-26
Tracking: #8, #11

This document is the single checklist for data or behavior that Mediarama deliberately does **not** carry forward from Coppermine.

An intentional omission is not the same as an unknown gap:

- **intentional omission**: source semantics are understood, the target decision is explicit, and the reason is documented here;
- **unsupported/block**: potentially important source state exists but Mediarama cannot preserve it safely yet, so preflight must stop migration;
- **transform/historical**: useful meaning is preserved in another form.

The migration loss matrix remains the table-by-table inventory. This document supplies the rationale for every deliberate omission or deliberate reduction of source detail.

## 1. Derived keyword dictionary

Source: `dict`.

Decision: **do not migrate the table**.

Reason:

Coppermine's own `keyword_create_dict.php` rebuilds the dictionary by truncating `TABLE_DICT`, reading non-empty `pictures.keywords`, splitting by the configured keyword separator and inserting unique values. The dictionary is therefore derived/index data, not the source of truth.

Mediarama imports source keywords into normalized `tags` and `media_tags`. Recreating the old dictionary table would duplicate derived data and preserve an implementation detail rather than meaning.

Preserved meaning: picture keywords/tags.

## 2. Active Coppermine sessions

Source: `sessions`.

Decision: **never migrate active sessions or remember-me state**.

Reason:

Coppermine session rows contain an authentication/session identifier, user ID, last activity time and remember flag. The native bridge code creates, updates, expires and reverts these rows as live authentication state. Carrying them into Mediarama would attempt to keep credentials/tokens from a different authentication system alive.

Mediarama deliberately requires imported identities to establish new authentication state. Active Coppermine sessions expire with the old application.

Preserved meaning: user identity and last-login information where appropriate, not authentication tokens.

## 3. Temporary cross-page messages

Source: `temp_messages`.

Decision: **do not migrate**.

Reason:

Coppermine stores these messages only to carry a redirect/status message between requests. `cpgFetchTempMessage()` deletes a row immediately after it is read, and `cpgCleanTempMessage()` garbage-collects old rows (default one hour). They are ephemeral request-flow state, not gallery content or history.

Preserved meaning: none required.

## 4. E-card product feature and sent-e-card log

Sources:

- `ecards`;
- group capability `can_send_ecards`;
- e-card runtime/UI behavior.

Decision: **do not recreate the e-card feature and do not import its sent-log into the Mediarama product database**.

Reason:

Coppermine writes an e-card log only when e-card logging is enabled and mail was successfully sent. The log can contain sender/recipient names and email addresses, sender IP, date and an encoded serialized message/link payload. Mediarama has no e-card product feature in the target architecture, and importing this legacy correspondence would retain personal/network data without a product need.

If a particular installation has a legal/archive obligation to retain e-card history, that should be handled as a separate restricted archival/export process before the source is retired, not by turning Mediarama into the archive.

Preserved meaning: none in the product model.

## 5. Detailed hit telemetry

Source: `hit_stats`.

Decision: **omit raw rows; preserve aggregate view history**.

Reason:

Raw hit rows can contain IP address, search phrase, timestamp, referrer, browser, operating system and user ID. Those details are analytics/network telemetry, not necessary gallery content.

Mediarama preserves the user-visible product counters:

- `pictures.hits` → media `view_count`;
- `albums.alb_hits` → collection `view_count`.

This keeps historical popularity without carrying the privacy-heavy event log.

## 6. Comment network identifiers

Source fields include comment raw/header IP data.

Decision: **do not migrate IP/network identifiers**.

Reason:

The comment itself, author/guest name, timestamp and moderation/spam state are sufficient to preserve the interaction. Historical IP addresses are not required for the comment domain and should not be retained automatically merely because the old application stored them.

Preserved meaning: comment content, authorship where recoverable, date and moderation state.

## 7. Rating/vote client telemetry and unrecoverable vote detail

Sources:

- `votes`;
- `vote_stats`.

Decision:

- preserve recoverable user-linked rating values;
- preserve source aggregate rating/vote history;
- **do not migrate IP/referrer/browser/OS telemetry**;
- **do not invent individual ratings when Coppermine does not store the rating value**.

Reason:

The basic `votes` table records that a voter has voted but does not contain the individual rating value. Reconstructing an individual rating from an aggregate would manufacture data that never existed. Detailed `vote_stats` rows can supply a rating value and source user ID; those are migrated when recoverable, while unrelated network/client telemetry is omitted.

Preserved meaning: recoverable ratings plus auditable aggregates.

## 8. Picture upload/last-hit IP fields

Source fields include `pic_raw_ip`, `pic_hdr_ip` and `lasthit_ip`.

Decision: **do not migrate**.

Reason:

These are historical network identifiers from upload/view activity. They are not required to preserve the media asset, ownership, metadata or aggregate view count. Security/audit retention must be a separate explicit policy, not an accidental property of the media record.

## 9. Anonymous browser-local favorites

Source: anonymous favorites cookie.

Decision: **server migration does not transfer anonymous browser-local favorites**.

Reason:

For an anonymous visitor, the favorite list can exist only in that visitor's browser cookie and is not represented by a durable server-side user record that a database migration can reliably associate with a future Mediarama identity.

Authenticated `favpics` data is migrated to normalized favorites. Anonymous favorites would require a separate client-side, opt-in migration mechanism if ever justified.

## 10. Legacy authentication and unlock tokens

Examples:

- `users.user_actkey`;
- old password/session credentials;
- album unlock cookies;
- guest/browser tokens used by the old runtime.

Decision: **do not reuse or migrate as live credentials**.

Reason:

These values authorize actions in Coppermine's security model. Reusing them would couple Mediarama security to legacy tokens and could extend credentials beyond their intended lifetime. Imported active users require Mediarama password re-establishment, and password-protected albums require a new target password/policy.

Preserved meaning: identity/account state and the fact that album password protection existed, not the credential itself.

## 11. Coppermine plugin registry as target runtime state

Source: `plugins`.

Decision: **do not import Coppermine plugin registry rows into Mediarama's future extension system**.

Reason:

Plugin name/path/enabled/priority describe the Coppermine runtime, not a portable Mediarama extension. More importantly, plugins may own their own tables/files/configuration.

Therefore this is not a silent discard:

- any installed plugin row blocks migration;
- any database table using the configured Coppermine prefix that is not part of the audited 1.6/1.7 core schema also blocks migration, covering orphaned plugin/custom tables whose registry entry has disappeared.

Those checks cannot prove that an extension never stored data under another prefix or outside the database, so a real-installation migration still requires an operator review of extension/customization history.

Preserved meaning: plugin/custom business data only after case-by-case audit; the legacy registry itself is not target runtime state.

## 12. Derived Coppermine EXIF cache

Source: `exif.exifData`.

Decision: **do not migrate the serialized cache as authoritative metadata**.

Reason:

Coppermine 1.6 and 1.7 populate this table by reading EXIF from the picture file, filtering the parsed result to configured EXIF fields and serializing that derived array for later reads. When picture metadata is refreshed/edited, Coppermine deletes the cached row so it can be rebuilt from the file.

Mediarama instead re-extracts the migrated original with ExifTool, preserving a richer EXIF/IPTC/XMP snapshot plus normalized canonical metadata. Importing the old serialized cache would preserve a potentially stale and deliberately reduced derivative rather than the source media metadata.

CI includes a deliberately stale source cache value and proves that the target keeps the value read from the original JPEG.

Preserved meaning: embedded metadata from the original media file, not the source application's cache implementation.

## 13. Dynamic/automatic cover selection

Source values:

- `thumb = 0`;
- negative album thumbnail values used for runtime/random selection.

Decision: **do not freeze a transient runtime choice into `cover_media_id`**.

Reason:

Only a positive source PID is a stable explicit media choice. Automatic/random behavior is a rule, not a persistent chosen media ID. Mediarama leaves the cover unpinned so its own automatic cover behavior can operate.

Preserved meaning: explicit positive cover PIDs are migrated.

## What is not an intentional omission

The following are **not** allowed to disappear under this policy:

- non-empty custom media fields `user1..4`;
- non-empty user profile fields `user_profile1..6`;
- non-zero multi-root `url_prefix`;
- user-gallery `galleryicon`;
- finite effective quotas;
- source upload-approval requirements;
- restricted thumbnail/intermediate/full-size access levels;
- bans;
- bridged identity authority;
- unknown plugin-owned data;
- duplicate normalized emails;
- ambiguous private-album state.

Those states are unresolved target mappings/security semantics and therefore block migration until explicitly handled.

## Exit rule

A future intentional omission may be added only when all of the following are true:

1. primary-source behavior is understood;
2. the useful source meaning is identified;
3. any preserved subset/aggregate is specified;
4. privacy/security implications are considered;
5. the reason for not carrying the remainder is written here;
6. the loss matrix uses the same classification.

This keeps “we deliberately do not migrate it” distinct from “we forgot it.”
