# ADR-0011: Retire Coppermine from architecture discovery after validated migration evidence

- Status: Proposed — blocked on real Coppermine 1.6 migration evidence
- Date: 2026-09-26

## Context

Mediarama began by studying Coppermine because it represents a mature gallery product and because existing Coppermine installations need a trustworthy migration path.

That research has now produced:

- a broad functional inventory of Coppermine 1.6 and 1.7;
- a field/table-level migration loss matrix;
- explicit intentional-omission policy;
- fail-closed preflight for unsupported/ambiguous source states;
- staged, resumable, source-instance-scoped migration;
- representative synthetic 1.6 and 1.7 migration coverage;
- large-library failure/resume coverage;
- metadata, ACL, interaction, media-type and hierarchy migration tests;
- source-provenance/license review.

Mediarama's runtime architecture is no longer a Coppermine modernization or port. It has its own domain model, storage, metadata, authorization, processing, frontend and extension direction.

However, source research alone is not enough to close architecture discovery. At least one representative real or appropriately anonymized Coppermine 1.6 installation must still be migrated and reconciled successfully.

## Proposed decision

**After the real-world evidence gate passes**, Coppermine will cease to be an open architecture-discovery input for Mediarama.

Coppermine will remain relevant only for:

1. migration compatibility;
2. importer bug fixes;
3. newly discovered source edge cases affecting existing migrations;
4. historical/reference evidence when explaining a migration decision.

New Mediarama product architecture will not be selected merely because Coppermine implemented a feature in a particular way.

## Evidence required before acceptance

This ADR must remain **Proposed** until all of the following are true:

- issue #11 real/anonymized 1.6 gallery test is checked;
- issue #11 real-world migration evidence is checked;
- the source passed current preflight or every source-specific blocker was explicitly resolved;
- migration completed on a dedicated clean target;
- async media processing completed without failed source media;
- reconciliation reported no missing/unmapped source pictures;
- the generated real-gallery evidence report was reviewed for provenance/privacy;
- no new unresolved high-value architecture requirement emerged from the real installation.

Validation procedure: docs/research/coppermine-real-gallery-validation.md

Executable evidence harness: bin/validate-coppermine-real

## Architecture boundary after acceptance

### Coppermine remains allowed to change

- source schema/version detection;
- preflight validation;
- migration transforms;
- source-specific compatibility adapters;
- reconciliation/reporting;
- regression fixtures;
- privacy/security handling required by source data;
- documented support boundaries.

### Coppermine no longer drives

- Mediarama collection architecture;
- public routing/SEO;
- frontend/theme architecture;
- identity/authentication architecture;
- media-processing architecture;
- metadata model;
- extension/plugin model;
- Smart Collection design;
- AI organization design;
- product roadmap.

Those decisions belong to Mediarama requirements and Mediarama ADRs.

## Reopening architecture discovery

A future Coppermine discovery does **not** automatically reopen this ADR.

Architecture discovery should be reopened only if migration evidence proves that a previously unknown Coppermine capability represents:

- important user-owned data that cannot be preserved by the current target model; or
- a security/access semantic that cannot be represented safely; or
- a high-value user outcome that Mediarama explicitly decides it must support.

Ordinary importer bugs and source variants remain compatibility work.

## Source/licensing boundary

Current provenance evidence supports Mediarama as a clean implementation informed by source-format and behavioral facts rather than retained/copied Coppermine runtime implementation.

Coppermine 1.6/1.7 are GPLv3 references. Mediarama currently uses GPL-3.0-or-later.

If future work deliberately copies or adapts upstream implementation code, that change must carry explicit provenance/copyright/license review. This ADR does not grant an exception.

## Consequences after acceptance

- the exhaustive Coppermine research phase can close;
- issue #11 can close;
- PR #12 can move from draft research toward final review/merge;
- future Coppermine work is scoped as migration compatibility/bug-fix work;
- Mediarama product development can proceed without continually treating Coppermine as an architectural authority;
- new product work such as SEO, Smart Collections and organization intelligence remains governed by Mediarama's own roadmap/ADRs.

## Current status

**Not accepted yet.**

The remaining blocking evidence is a representative real/anonymized Coppermine 1.6 migration and its reviewed evidence report.

Changing this ADR to Accepted before that evidence exists would contradict the exit criteria in issue #11.
