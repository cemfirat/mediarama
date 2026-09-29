# Coppermine exit audit

Status: **open — architecture closure explicitly blocked**
Date: 2026-09-26

Mediarama must not declare Coppermine research complete merely because the importer works or because the main architectural direction is already clear.

The architecture topic can be closed only after an exhaustive, evidence-based audit of Coppermine 1.6.x and 1.7.x, representative migration tests and an explicit source-provenance/license review.

Tracking issue: #11.

## Audit principle

Coppermine is being studied for three separate reasons:

1. **product knowledge** — mature gallery behavior worth preserving or deliberately replacing;
2. **migration completeness** — data and behavior that must survive a move to Mediarama;
3. **architecture lessons** — strengths and weaknesses that can inform Mediarama without binding it to Coppermine's implementation.

A feature found in Coppermine is not automatically a Mediarama requirement. A feature omitted from Mediarama must nevertheless be identified and consciously classified.

## Evidence hierarchy

Use the following order whenever possible:

1. current source of `coppermine-gallery/cpg1.6.x`;
2. source of `coppermine-gallery/cpg1.7.x`;
3. SQL schema and upgrade/install scripts;
4. bundled official documentation/changelog;
5. official Coppermine documentation;
6. representative real or anonymized installations.

Screenshots, README summaries and memory are not sufficient for closure decisions.

## Current confirmed findings from the first extended audit pass

The items below are **confirmed to exist** in the primary source. They are not yet considered exhaustively researched.

### Favorites

Coppermine has a dedicated favorites feature, exposed as the `favpics` meta album.

Relevant 1.6 evidence includes:

- `addfav.php`;
- `include/functions.inc.php` meta-album handling;
- `include/init.inc.php` favorites loading;
- theme navigation entries.

Favorites are persisted in two ways:

- a serialized/base64-encoded favorites cookie;
- for authenticated users, `TABLE_FAVPICS.user_favpics`.

Implication for Mediarama:

- favorites are a real mature product capability, not merely a UI shortcut;
- migration semantics need further study before deciding whether old favorite sets can and should be migrated;
- Mediarama already has a normalized favorites concept, so the remaining question is source extraction and identity mapping.

### E-cards

Coppermine 1.6 contains `ecard.php` and `db_ecard.php`, group capability checks and an administrative sent-e-card log.

1.7 still contains at least the e-card logging/admin surface and inherited theme entries.

This is a candidate for **intentional omission**, not automatic recreation. Before that decision is final, the audit must identify:

- data persisted by the feature;
- privacy implications;
- whether any migration data would otherwise be lost;
- whether mail/share workflows in Mediarama should replace it.

### External application bridging

Both lines contain `bridgemgr.php` and explicit bridge-management UI.

The bridge system can delegate or integrate user authentication with another application and contains recovery/fallback behavior for disabling a failed bridge.

Implication:

- this is broader than simple login;
- a Mediarama replacement should likely be based on modern external identity/OIDC/SAML/plugin integration rather than source-compatible bridges;
- migration from bridged installations must be researched carefully because local Coppermine users may not represent the real identity authority.

### Search and keyword behavior

Coppermine exposes:

- a dedicated search page;
- keyword lists;
- full-text search behavior distinct from the keyword list;
- clickable keyword search configuration;
- keyword administration;
- browse-by-date/calendar behavior.

Implication:

- Mediarama's PostgreSQL search should not be considered feature-complete merely because metadata full-text search exists;
- the audit must inventory exact searchable fields, phrase/keyword semantics, category/album restrictions, date browsing and configuration switches.

### Logging and administration

Coppermine contains configurable logging through `include/logger.inc.php`, a `viewlog.php` admin surface, keyword manager, EXIF manager, database-update UI and other admin tools.

Implication:

- admin/maintenance workflows deserve their own inventory;
- some are implementation-specific and should not be copied;
- the user outcomes — diagnosis, consistency checking, bulk metadata configuration, log inspection, repair and upgrades — may still be important Mediarama requirements.

### EXIF/IPTC administration

Coppermine exposes EXIF configuration/management through `exifmgr.php` and configuration entries, and supports IPTC-reading configuration.

Mediarama already goes substantially further with ExifTool and a canonical metadata model, but the audit still has to document:

- which fields Coppermine can display/store;
- configurable EXIF field selection;
- IPTC import behavior;
- interaction between source metadata and edited captions/keywords;
- what a migration must preserve.

### Virtual user galleries

Coppermine reserves the `FIRST_USER_CAT = 10000` range for user-gallery behavior. These are not ordinary category records.

This has already affected the importer design: user-gallery album categories must not be treated as missing normal categories.

Further work is needed on:

- ownership;
- public/private behavior;
- per-user album creation;
- migration hierarchy and URLs;
- interactions with group permissions.

## 1.6 vs 1.7: caution already established

The current evidence still supports these provisional conclusions:

- 1.7 is not a clean architectural rewrite;
- 1.7 retains the page-oriented PHP core, theme/plugin mechanisms and much legacy behavior;
- 1.7 includes Theme2/responsive/touch-navigation experiments worth inspecting;
- 1.6 contains later maintenance/security/PHP-compatibility changes absent from the dormant 1.7 repository.

This does **not** mean the comparison is finished.

The final audit must still compare schema, configuration, hooks, media handling, search, auth/authorization, metadata and removed components field-by-field or capability-by-capability.

## Migration classification model

Every identified Coppermine capability/data type must end with one of these classifications:

| Classification | Meaning |
| --- | --- |
| Direct | Migrate with equivalent semantics |
| Transform | Migrate into a different Mediarama model |
| Historical | Preserve only as aggregate/audit/history |
| Omit intentionally | Do not reproduce; reason documented |
| Blocking gap | Migration/architecture cannot be signed off yet |

No feature should disappear merely because it was missed during research.

## Intentional omissions now explicitly classified

The migration audit now has a single omission policy in `docs/research/coppermine-intentional-omissions.md`.

It distinguishes deliberate non-migration from unresolved gaps and documents the rationale for:

- derived keyword dictionary rows;
- live sessions and temporary redirect messages;
- the legacy e-card feature/log;
- detailed hit/vote/client/network telemetry while retaining useful aggregates;
- comment/picture historical IP fields;
- anonymous browser-local favorites;
- legacy activation/session/unlock credentials;
- the Coppermine plugin registry as target runtime state;
- automatic/random cover choices that are rules rather than stable media selections.

This closes the documentation task for **currently identified** intentional omissions. It does not close the migration audit itself: unsupported/blocking source states, real-gallery evidence and provenance/licensing remain open.

## Architecture closure gate

Coppermine is **not** considered closed as an architecture topic until all of the following are true:

- complete functional inventory exists;
- 1.6/1.7 differences relevant to Mediarama are documented;
- migration loss/classification matrix is complete;
- representative real-world migration evidence exists;
- importer limitations are explicit and tested;
- security-sensitive legacy behavior has been reviewed;
- source provenance and license implications are audited;
- no unresolved high-value capability remains;
- a final ADR explicitly records the move from architecture discovery to compatibility-only maintenance.

Until that point, the statement is:

> Mediarama has its own architecture direction, but Coppermine research remains open and can still change requirements or migration design.

## Remaining exit-audit batches

The broad source inventory and synthetic migration gates are now substantially covered. Remaining work should focus on evidence that cannot be replaced by more synthetic discovery:

1. migrate at least one representative real/anonymized 1.6 installation;
2. finish remaining source config/language policy decisions;
3. finish source-only persisted EXIF comparison where file re-extraction may be insufficient;
4. classify remaining per-album behavior flags such as upload/comment/vote policy;
5. make import persistence source/run scoping production-safe before beta;
6. complete source provenance and licensing review;
7. write the final architecture-exit ADR only after those gates are satisfied.

Coppermine remains an open migration/reference topic until those gates close.
