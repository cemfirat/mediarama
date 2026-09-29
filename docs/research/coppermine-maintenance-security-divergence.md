# Coppermine maintenance and security divergence after 1.7

Status: **verified maintenance-history audit**
Date: 2026-09-25
Tracking: #11

## Why this matters

Coppermine 1.7 contains useful experimental changes, but it stopped evolving in 2023 while 1.6 continued receiving fixes.

Therefore a feature/code path that looks "newer" in 1.7 must not automatically replace current 1.6 behavior in Mediarama research.

## Current 1.6 maintenance after the visible 1.7 development window

The current 1.6 changelog records later work including:

### 2023

- HTML5 upload/admin notification corrections;
- Cloudflare Rocket Loader compatibility for HTML5 upload initialization;
- post-upload race-condition mitigation;
- dimension-restriction upload cleanup fix;
- PHP 8 EXIF parsing correction;
- PDO query exception handling;
- IPv6 compatibility in banning;
- **2023-11-27:** IPTC supplemental-category parsing correction.

### 2024

- **2024-06-12:** hit/vote statistic IP storage expanded for IPv6.

### 2025

- PHP deprecation cleanup.

### 2026

- single-file upload failure UX/error handling;
- PHP 8.1 deprecation correction;
- **security fix for the language-check helper**;
- **mitigation of several minor vulnerabilities**;
- HTML5 upload error-condition handling;
- language maintenance.

## Concrete source divergences already verified

### IPv6 statistics storage

Current 1.6:

- `hit_stats.ip varchar(40)`
- `vote_stats.ip varchar(40)`

1.7:

- both remain `varchar(20)`

The 1.6 SQL update explicitly states that the 40-character change accommodates IPv6.

### IPTC supplemental categories

Current 1.6 keeps IPTC SubCategories as a repeatable value list.

The checked 1.7 code has the older behavior that returns only the first value.

## Security conclusion

1.7 should **not** be used as a wholesale source baseline.

For any 1.7-only code/idea that Mediarama chooses to study or adapt conceptually:

1. identify the corresponding current 1.6 path;
2. inspect later 1.6 security/maintenance changes;
3. preserve the corrected behavior, not the stale 1.7 behavior;
4. preferably reimplement the requirement cleanly rather than transplanting legacy code.

## Mediarama implication

This strengthens the clean-core decision.

The research value is:

- 1.6 → current behavioral/security maintenance reference;
- 1.7 → selected experimental UX/media/theme ideas;
- Mediarama → independent implementation with modern tests and architecture.

## Remaining security audit

Before Coppermine architecture closure:

- inspect the actual 2026 security-related diffs at a safe requirements level;
- inventory historical upload/path/shell/XSS lessons relevant to media ingestion;
- review bridge/auth recovery risks;
- review plugin upload/install risks;
- review old serialized cookie/DB state that should never be accepted as trusted input by Mediarama;
- turn applicable lessons into Mediarama security tests.
