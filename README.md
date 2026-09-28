<p align="center">
  <img src="https://raw.githubusercontent.com/cemfirat/repository-governance/main/assets/brand-banner.webp" alt="Cem Firat creative consultancy artwork" width="900" />
</p>

# Mediarama Gallery

Official domain: **https://mediarama.gallery**

Mediarama is a modern photo and video gallery platform inspired by the proven feature set and workflows of [Coppermine Photo Gallery](https://coppermine-gallery.net/).

The project is **not intended to be a visual reskin of Coppermine**. The goal is to understand what Coppermine does well, compare its current development lines, identify architectural and technical legacy, and use those findings to design a maintainable, modern media gallery platform.

## Project status

**Phase: architecture baseline and foundation planning**

The initial research phase is complete enough to establish the architecture baseline: Mediarama will be a clean implementation rather than a permanent Coppermine fork, PostgreSQL is the primary database, media storage is abstracted, originals are immutable by default, heavy media processing is asynchronous, and UIkit is the presentation foundation. Detailed decisions are recorded as ADRs.

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

The first research phase compares both active Coppermine codebases:

- [Coppermine 1.6.x](https://github.com/coppermine-gallery/cpg1.6.x)
- [Coppermine 1.7.x](https://github.com/coppermine-gallery/cpg1.7.x)

The comparison should cover at least:

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

The purpose is not to assume that 1.7.x is automatically the correct foundation. Its actual architectural differences from 1.6.x must first be established.

## Frontend and design system

Mediarama should use **UIkit (getuikit)** as its primary UI framework.

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

The exact framework and implementation are intentionally not fixed yet. They should be selected after the Coppermine analysis and requirements inventory.

## Database evaluation

Coppermine's existing database model and MySQL dependencies will be analyzed before selecting Mediarama's persistence layer.

**PostgreSQL is a candidate, not yet a decision.**

The evaluation should include:

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

If PostgreSQL offers meaningful architectural advantages, Mediarama may use it. Database abstraction should nevertheless be considered so application logic is not unnecessarily coupled to vendor-specific SQL.

## Fork vs. new foundation

A permanent fork of Coppermine is **not assumed**.

Three approaches must be evaluated:

1. **Direct fork**  
   Modernize Coppermine incrementally while retaining its codebase.

2. **Deep refactor**  
   Use Coppermine as the starting codebase but progressively replace major architectural layers.

3. **New Mediarama core with Coppermine compatibility/migration**  
   Treat Coppermine as the functional reference and migration source while implementing a clean architecture for Mediarama.

The decision should consider development effort, security, maintainability, technical debt, upgrade paths, licensing, migration requirements and the amount of Coppermine code that can realistically remain useful.

## Compatibility and migration

Existing Coppermine installations are important.

Even if Mediarama becomes a new implementation, the project should investigate migration of:

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

## Research before implementation

Before large implementation work begins:

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

After the research phase, development should be organized using:

- milestones
- focused issues
- labels
- architecture decision records (ADRs)
- dependency-aware implementation phases
- documented acceptance criteria
- tests and verification requirements

Large umbrella tasks should be split into independently verifiable work instead of creating an unstructured backlog.

## Initial roadmap

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
- [ADR-0006 — Symfony 7.4 LTS, PHP 8.5 and Doctrine](docs/adr/0006-symfony-php-doctrine.md)
- [Initial PostgreSQL Schema](docs/architecture/database-schema.md)
- [Media Storage Contract](docs/architecture/media-storage.md)

The current working hypothesis is to build a new Mediarama core, use the actively maintained Coppermine 1.6.x line as the primary functional and migration reference, and use selected 1.7.x Theme2/responsive work as additional UX research. This remains subject to the remaining discovery work and formal architecture decisions.

## Current next step

The immediate task is a **deep technical comparison of Coppermine 1.6.x and 1.7.x**.

The findings will determine Mediarama's architecture and will be converted into professional GitHub milestones, issues and architecture decisions before substantial implementation begins.


## License

Mediarama Gallery is licensed under **GPL-3.0-or-later**. See `LICENSE` for the complete GPLv3 text and [Licensing and provenance policy](docs/governance/licensing.md) for the project policy and Coppermine reuse rules.
