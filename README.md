<p align="center">
  <img src="https://raw.githubusercontent.com/cemfirat/repository-governance/main/assets/brand-banner.webp" alt="Cem Firat creative consultancy artwork" width="900" />
</p>

# Mediarama

Official domain: **https://mediarama.gallery**

Mediarama is a modern photo and video gallery platform inspired by the proven feature set and workflows of [Coppermine Photo Gallery](https://coppermine-gallery.net/).

The project is **not intended to be a visual reskin of Coppermine**. The goal is to understand what Coppermine does well, compare its current development lines, identify architectural and technical legacy, and use those findings to design a maintainable, modern media gallery platform.

## Project status

**Evidence checked: 31 October 2026, 21:42 UTC.** This section is the dated project entrypoint, not live telemetry. The source baseline checked for this documentation update is [`main@ca4c3f4dacb9ad1e61c9cd387404fe97caac6018`](https://github.com/cemfirat/mediarama/commit/ca4c3f4dacb9ad1e61c9cd387404fe97caac6018). The active product roadmap is [#17](https://github.com/cemfirat/mediarama/issues/17); this reconciliation is tracked in [#162](https://github.com/cemfirat/mediarama/issues/162).

**Phase: implemented application foundations and gallery intelligence, with open metadata and migration evidence work.** Accepted ADRs establish a clean implementation, PostgreSQL, PHP/Symfony/Doctrine, storage abstraction, immutable originals, asynchronous media processing and UIkit presentation. Initial technology selection is no longer the next task.

| Layer | Verified source or record | Remaining boundary |
| --- | --- | --- |
| Source and CI | The source baseline above has a successful [full CI run 39448421068](https://github.com/cemfirat/mediarama/actions/runs/39448421068). | This existing run does not validate a later candidate or prove a deployed installation. |
| Gallery intelligence | [#17](https://github.com/cemfirat/mediarama/issues/17) records #13 SEO/public discovery, #14 deterministic Smart Collections and #16 proposal-based organization AI as implemented. | Optional AI keeps the normal proposal, approval, privacy and publication boundaries. This milestone does not close the Coppermine exit gate. |
| Metadata Workspace | [PR #152](https://github.com/cemfirat/mediarama/pull/152), merge `cd0fec559fe8cc95cd934a52b731f614a6f6c051`, integrated an authenticated owner-only, read-only inspector with separate source/current/provenance views. | Typed editing, clear/revert behavior, batch operations and templates remain in [#91](https://github.com/cemfirat/mediarama/issues/91); the inspector is not their completion. |
| Coppermine exit audit | [#19](https://github.com/cemfirat/mediarama/issues/19) still requires a representative real/anonymized Coppermine 1.6 database, matching media and permission to use them. | [#11](https://github.com/cemfirat/mediarama/issues/11) and draft [PR #12](https://github.com/cemfirat/mediarama/pull/12) remain open. Synthetic fixtures cannot replace this evidence. |
| Release, installation and acceptance | No GitHub Release was present in the checked repository release list. No host or customer installation was inspected for this update. | Publication, installation, browser/owner acceptance and the 1.0 production/LTS policy remain separately evidenced decisions. The publication and installation stop remains in force. |

The Technical Lead owns implementation, independent review, verification and this dated handoff. The product owner decides goals, priorities, budget and visible/functional acceptance. Read [AGENTS.md](AGENTS.md), the applicable ADRs, the active issue and current GitHub evidence before continuing. A source merge or successful CI is not a release, installation or product acceptance.

## Runtime baseline

Current development baseline:

- PHP **8.5+**
- Symfony **8.1.x**
- Doctrine ORM **3.x** / DBAL **4.x**
- Doctrine Migrations Bundle **4.x**
- PostgreSQL

Framework/runtime upgrades are reviewed in small increments and require fully green branch CI before a pull request is opened. See [Runtime and dependency maintenance](docs/architecture/runtime-dependency-maintenance.md) for the authoritative maintenance policy, tested runtime baseline and dependency-update workflow. The production/LTS support policy for Mediarama 1.0 will be defined deliberately before release freeze.

## First-run setup

A new installation starts in a persisted **pending** setup state and defaults to **Private workspace**. The first administrator is created deliberately through `/setup` or the server-side `mediarama:setup:bootstrap-admin` command.

Browser setup is disabled unless a high-entropy `MEDIARAMA_SETUP_TOKEN` is configured on the server. Setup uses the normal Mediarama user/group/`system.admin` permission model, never a permanent bootstrap superuser. Existing or migrated administrator identities are recovered/reconciled instead of silently creating duplicate administrators.

See [docs/architecture/setup-bootstrap.md](docs/architecture/setup-bootstrap.md) for the security model and recovery procedure.

## Vision

Mediarama should preserve the strengths that made Coppermine useful while removing technical constraints that accumulated over many years.

Core principles:

- clean separation of application logic, data access and presentation
- no table-based page layouts
- responsive, accessible and maintainable frontend
- UI built consistently with [UIkit](https://getuikit.com/)
- modern photo and video management
- clear frontend/backend separation
- extensible architecture for future media types and metadata
- secure-by-default implementation
- maintainable upgrade and migration paths
- avoid unnecessary rewrites of functionality that is already proven
- avoid carrying legacy architecture forward merely for compatibility

## Reference projects

The original discovery work compared these Coppermine codebases:

- [Coppermine 1.6.x](https://github.com/coppermine-gallery/cpg1.6.x)
- [Coppermine 1.7.x](https://github.com/coppermine-gallery/cpg1.7.x)

Its original scope included the following areas; the linked research and accepted ADRs record the findings and the open exit audit retains the remaining evidence requirements:

- application architecture
- PHP/runtime requirements
- database schema and database access
- albums and categories
- media/file model
- image and video handling
- upload workflows
- metadata
- users, groups, permissions and privacy
- authentication and sessions
- comments, ratings and community features
- search
- thumbnails and image processing
- administration
- themes/templates
- plugins/hooks/extensions
- localization
- configuration
- caching and performance
- security model
- APIs and integrations
- installation and upgrades
- migration compatibility
- tests and development tooling
- known legacy dependencies

The resulting [clean-implementation decision](docs/adr/0001-clean-implementation.md) uses Coppermine 1.6 as the primary behavioral/migration reference and selected 1.7 work as additional UX research. Remaining real-source migration evidence is tracked separately in #19.

## Frontend and design system

Mediarama uses **UIkit (getuikit)** as its presentation foundation under [ADR-0005](docs/adr/0005-uikit-presentation.md).

The frontend should use semantic, responsive structures such as:

- Grid and Flex
- Cards
- Navbar and navigation components
- Modal
- Offcanvas
- Drop/Dropdown
- responsive media components
- modern forms
- notifications
- utility classes

Application logic must not generate presentation-specific table layouts.

UIkit should be integrated as a real design system rather than added as a cosmetic layer over legacy markup. Project-specific styling should remain customizable without requiring changes to core application logic.

## Architecture direction

A key architectural goal is separation of concerns.

Conceptually:

```text
Media / Domain
      |
Application Services
      |
Data Access
      |
Database

Presentation
      |
UIkit-based Views
```

The framework is fixed by accepted [ADR-0006](docs/adr/0006-symfony-php-doctrine.md): PHP 8.5, Symfony 8.1, Twig and Doctrine, with infrastructure concerns kept behind the application boundaries. Runtime updates follow the existing maintenance policy; this documentation update changes no dependency or runtime requirement.

## Database decision

Accepted [ADR-0002](docs/adr/0002-postgresql.md) selects **PostgreSQL as the primary and initially supported database**. MySQL/MariaDB are Coppermine source systems for the importer, not an additional promised Mediarama v1 backend.

The original evaluation considered:

- relational integrity
- compatibility with the required media model
- metadata flexibility
- JSON/JSONB use cases
- full-text search
- indexing
- migrations
- performance
- operational complexity
- hosting requirements
- portability
- migration from existing Coppermine installations

Vendor-specific persistence belongs in infrastructure/repository implementations. A second runtime database backend would require its own decision and test matrix.

## Clean implementation decision

Accepted [ADR-0001](docs/adr/0001-clean-implementation.md) selects a new Mediarama application with a dedicated Coppermine importer. A permanent fork and a progressive in-place refactor are historical alternatives, not unresolved current choices.

The original evaluation compared:

1. **Direct fork**  
   Modernize Coppermine incrementally while retaining its codebase.

2. **Deep refactor**  
   Use Coppermine as the starting codebase but progressively replace major architectural layers.

3. **New Mediarama core with Coppermine compatibility/migration**  
   Treat Coppermine as the functional reference and migration source while implementing a clean architecture for Mediarama.

The decision weighs maintainability, security, migration and licensing consequences. Coppermine remains a functional and migration reference; any deliberate source reuse still requires its own licensing review.

## Compatibility and migration

Existing Coppermine installations are important.

The migration scope to validate includes:

- albums and categories
- media files
- thumbnails/derivatives where useful
- users and groups
- permissions
- metadata
- comments
- ratings
- configuration where meaningful

A reliable migration path may provide more long-term value than maintaining source-level compatibility with Coppermine.

## Original research sequence — historical

This was the initial discovery sequence. It is retained as planning history; accepted ADRs and implemented work above supersede its former position as the immediate implementation gate. Open source-specific audit work remains in #11/#19.

1. Analyze Coppermine 1.6.x.
2. Analyze Coppermine 1.7.x.
3. Document their differences.
4. Inventory Coppermine's functional capabilities.
5. Identify technical debt and architectural constraints.
6. Define Mediarama's functional requirements.
7. Propose the Mediarama target architecture.
8. Evaluate database options.
9. Decide fork vs. refactor vs. new core.
10. Define migration strategy.
11. Convert the findings into GitHub milestones and actionable issues.

## GitHub project structure

Development is organized using:

- milestones
- focused issues
- labels
- architecture decision records (ADRs)
- dependency-aware implementation phases
- documented acceptance criteria
- tests and verification requirements

Large umbrella tasks should be split into independently verifiable work instead of creating an unstructured backlog.

## Original roadmap — historical

These phases describe the original planning structure. Use the dated Project status section and #17 for actual progress, dependencies and remaining acceptance work; the phase labels below are not a claim that implementation has yet to begin.

### Phase 0 — Discovery
Coppermine 1.6/1.7 analysis and feature inventory.

### Phase 1 — Architecture
Target architecture, technology decisions, database decision and ADRs.

### Phase 2 — Foundation
Mediarama application skeleton, development environment, database migrations, testing and CI.

### Phase 3 — Media core
Media library, albums/categories, uploads, metadata, image/video processing and permissions.

### Phase 4 — UI
Complete UIkit-based frontend and administration interface.

### Phase 5 — Extended features
Search, community functionality, extensibility, localization and integrations.

### Phase 6 — Migration
Coppermine importer, migration validation and compatibility documentation.

### Phase 7 — Production readiness
Security review, performance, accessibility, upgrade process, documentation and release preparation.

## Research

Technical research is documented separately from the project overview:

- [Coppermine 1.6.x vs 1.7.x — Technical Research](docs/research/coppermine-1.6-vs-1.7.md)
- [Coppermine Data Model Analysis](docs/research/coppermine-data-model.md)
- [Coppermine Upload & Media Processing Analysis](docs/research/coppermine-upload-processing.md)
- [Coppermine Theme & Plugin Architecture Analysis](docs/research/coppermine-theme-plugin-architecture.md)

Architecture:

- [Mediarama Target Architecture](docs/architecture/target-architecture.md)
- [ADR-0001 — Clean implementation](docs/adr/0001-clean-implementation.md)
- [ADR-0002 — PostgreSQL](docs/adr/0002-postgresql.md)
- [ADR-0003 — Storage and immutable originals](docs/adr/0003-storage-and-originals.md)
- [ADR-0004 — Processing and moderation states](docs/adr/0004-processing-and-moderation.md)
- [ADR-0005 — UIkit presentation boundary](docs/adr/0005-uikit-presentation.md)
- [ADR-0006 — Symfony 8.1, PHP 8.5 and Doctrine](docs/adr/0006-symfony-php-doctrine.md)
- [Initial PostgreSQL Schema](docs/architecture/database-schema.md)
- [Media Storage Contract](docs/architecture/media-storage.md)
- [Runtime and dependency maintenance](docs/architecture/runtime-dependency-maintenance.md)

The accepted ADRs establish the new Mediarama core, PostgreSQL and the framework boundary. Remaining discovery for a real Coppermine source does not reopen those decisions automatically or waive the required migration evidence.

## Current next step

Continue the existing scoped work under [#17](https://github.com/cemfirat/mediarama/issues/17), including the remaining Metadata Workspace slices in #91. Check current branches and PRs before implementation; preserve the separate draft migration audit PR #12 and dependency maintenance PR #161.

For the Coppermine exit gate, the next permitted evidence step is the real/anonymized source package and permission described in #19, followed by its isolated migration/reconciliation procedure. For any source change, complete the relevant local checks and independent review, then full successful branch CI, an independent full PR run and an identical-tree merge check. No publication or installation follows from this documentation work.
