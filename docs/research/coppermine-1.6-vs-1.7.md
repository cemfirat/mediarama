# Coppermine 1.6.x vs 1.7.x — Technical Research

Status: **ongoing verified research — not eligible for architecture closure**
Date: 2026-09-25

This document records the first technical comparison used to decide how Mediarama should relate to Coppermine.

## Research closure warning

This document is **not** evidence that Coppermine research is complete. The remaining tasks below are now governed by the exhaustive exit audit in `docs/research/coppermine-exit-audit.md` and GitHub issue #11.

A working importer, a clear Mediarama architecture direction, or a successful synthetic migration fixture are insufficient reasons to close Coppermine as an architecture/research topic. Closure requires exhaustive capability inventory, 1.6/1.7 comparison, migration-loss analysis, real-world migration evidence and source-provenance/license review.

The goal is not to judge Coppermine as a product. It is to identify which concepts are valuable to preserve, which implementation choices are legacy constraints, and whether either existing branch is a suitable technical foundation for Mediarama.

## Executive finding

The current evidence does **not** support treating Coppermine 1.7.x as a modern rewrite.

Coppermine 1.7.x retains the fundamental application structure, database model, theme mechanism, plugin API and page-oriented PHP architecture of the 1.6 line. Its visible development in 2023 concentrated on cleanup, PHP compatibility and a second-generation theme/responsive effort rather than a replacement architecture.

At the same time, Coppermine 1.6.x has continued receiving maintenance and security fixes through 2026, while the last commit currently visible in the 1.7.x repository is from **2023-04-25**.

For Mediarama this means:

- do not automatically fork 1.7.x;
- use Coppermine primarily as a functional and migration reference;
- analyze its data model and extension points carefully;
- design a clean Mediarama core independently;
- retain the option to reuse isolated, legally compatible logic where doing so is genuinely advantageous;
- prioritize a high-quality Coppermine migration path over source-level compatibility.

This is an initial architecture direction, not yet a final ADR.

---

## 1. Repository status

### Coppermine 1.6.x

Repository:

- `coppermine-gallery/cpg1.6.x`
- default branch: `develop`
- license: GPL-3.0
- active maintenance observed through April 2026

Recent verified maintenance includes:

- 2026-04-27: minor vulnerability mitigation / version 1.6.29
- 2026-04-16: theme/menu correction and Russian language addition
- 2026-04-11: HTML5 upload error handling
- 2026-03-06: PHP deprecation and security fixes
- 2026-01-03: PHP 8.4 deprecation cleanup

This makes 1.6.x the more useful source for current Coppermine behavior, security fixes and compatibility work.

### Coppermine 1.7.x

Repository:

- `coppermine-gallery/cpg1.7.x`
- default branch: `main`
- license: GPL-3.0
- repository created 2023-03-30
- latest verified commit: **2023-04-25**

The visible development burst lasted roughly one month.

Representative commits include:

- initial full commit
- core cleanup
- Theme2 work
- small-screen/burger-menu work
- keyboard/touch navigation
- PHP 8.2 compatibility corrections
- input-filter relocation
- removal of SWF uploader

No later development has been observed in the repository as of this research date.

### Consequence

Mediarama should not assume that a higher Coppermine version number means a better long-term base.

For current maintenance knowledge, 1.6.x is actually more relevant. For experimental UI ideas, 1.7.x remains useful.

---

## 2. Application architecture

Both branches remain primarily traditional page-oriented PHP applications.

Typical characteristics include:

- many top-level PHP entry points such as `upload.php`, `search.php`, `register.php`, `usermgr.php`, `thumbnails.php`, `displayimage.php` and administrative scripts;
- shared include files;
- global configuration/state;
- functions producing HTML;
- SQL access mixed throughout application code;
- theme functions overriding core presentation functions;
- HTML fragments embedded in PHP heredocs/strings.

There is no evidence in 1.7.x of a transition to a modern layered architecture such as:

- HTTP/router layer;
- controllers;
- application/service layer;
- explicit domain model;
- repositories;
- independent view/template layer;
- dependency injection;
- modern package/module boundaries.

### Mediarama implication

Mediarama should not reproduce the page-script architecture.

The desired separation remains:

```text
HTTP / Controllers
        |
Application Services
        |
Domain
        |
Repositories / Persistence
        |
Database / Object Storage

Presentation
        |
UIkit Views / Components
```

The exact PHP framework or implementation technology remains open pending a dedicated architecture decision.

---

## 3. Database layer

### Existing Coppermine model

Coppermine remains MySQL-oriented.

Verified database options include:

- MySQLi
- PDO with MySQL

1.6.x still contains compatibility knowledge for older MySQL access in parts of its history; 1.7.x's database selector exposes MySQLi and PDO:MySQL.

Both repositories retain a SQL schema under:

`sql/schema.sql`

The application also contains direct SQL in functional scripts, for example through calls such as:

`cpg_db_query(...)`

This means that the existence of a PDO driver should **not** be confused with true database independence.

### PostgreSQL

Nothing found so far indicates PostgreSQL support in Coppermine 1.6 or 1.7.

For Mediarama, PostgreSQL remains a valid candidate because a new architecture can make the persistence choice independently of Coppermine's implementation.

Potential Mediarama benefits to evaluate:

- stronger relational constraints;
- JSONB for extensible media metadata;
- advanced indexing;
- full-text capabilities;
- transactional migrations;
- modern query capabilities.

However, PostgreSQL should be selected because it fits Mediarama's target model, not merely because it is different from MySQL.

### Migration concern

A PostgreSQL Mediarama would require a deliberate importer from Coppermine's MySQL schema.

That is acceptable if migration is treated as a product feature.

---

## 4. Presentation and themes

### 1.6.x

Coppermine's theme system uses files such as:

- `template.html`
- `theme.php`
- shared theme functions in `include/themes.inc.php`

The application loads and parses `template.html`, while theme PHP functions can override presentation behavior.

This is flexible for its era, but presentation logic remains strongly coupled to PHP functions and globally available state.

### 1.7.x

1.7.x retains the same underlying model.

Verified elements include:

- `template.html`
- `theme.php`
- `include/themes.inc.php`
- new `include/themes2.inc.php`
- new `css/theme2.css`
- Theme2 variants including `curve2` and `water_drop2`

The 1.7 commit history shows explicit work on:

- responsive/small-screen behavior;
- burger menus;
- keyboard/touch navigation;
- Theme2 image navigation;
- page/tab handling.

These are useful UX experiments.

They are **not**, however, evidence of a clean separation between application logic and presentation.

### Table layout

1.7.x still contains table-based presentation markup in multiple areas, including:

- album management;
- image management;
- crop UI;
- legacy themes;
- sample plugin configuration;
- sample theme templates.

Therefore 1.7.x does not satisfy Mediarama's core design requirement of a clean UIkit-based presentation layer.

### Mediarama direction

Mediarama should:

- avoid using HTML tables for page layout;
- use semantic markup;
- use UIkit Grid/Flex/Card/Nav/Modal/Offcanvas/etc.;
- reserve tables for genuinely tabular data;
- isolate view components from domain/application code;
- make theme/design customization possible without copying core logic.

---

## 5. Plugin and extension model

Both 1.6.x and 1.7.x contain:

`include/plugin_api.inc.php`

and use constructs such as:

- `CPGPluginAPI::filter(...)`
- plugin actions/hooks
- plugin loading from core initialization

This is an important Coppermine strength: functionality can be extended without modifying every core file.

However, the API is tied closely to Coppermine's globals, page lifecycle and HTML-oriented architecture.

### Mediarama implication

Do not copy the plugin system blindly.

Instead, preserve the idea of explicit extension points using a modern event/hook contract.

Possible future model:

```text
Events
- MediaCreated
- MediaUpdated
- MediaDeleted
- AlbumCreated
- UploadCompleted
- MetadataExtracted
- UserAuthenticated

Filters
- media metadata normalization
- filename policy
- visibility decisions
- rendered component extension points
```

Extension APIs should be documented, typed where possible, and separated from internal global state.

---

## 6. Upload architecture

Coppermine provides significant functional knowledge worth preserving.

The 1.6 documentation and code demonstrate multiple upload paths including:

- HTML5 multi-file upload;
- single upload;
- FTP/server-side batch add;
- group-based upload limits;
- automatic resizing;
- media type restrictions;
- notification workflows.

1.7 removed the legacy SWF uploader and continued HTML5 work.

This functional maturity is one of the strongest reasons to use Coppermine as a requirements reference.

### Mediarama direction

Mediarama should define one coherent upload pipeline rather than several unrelated scripts.

Conceptually:

```text
Receive upload
    |
Validate
    |
Store original
    |
Extract metadata
    |
Generate derivatives
    |
Persist media entity
    |
Assign albums/collections
    |
Emit events
```

Large-file/chunked uploads, video processing and asynchronous derivative generation should be evaluated explicitly.

---

## 7. Media model

Coppermine stores original media files on the filesystem and stores location, album association and metadata in the database.

The README explicitly distinguishes database records from physical media storage.

This separation remains conceptually sound.

### Mediarama should generalize it

Instead of assuming only a local webroot filesystem, Mediarama should consider a storage abstraction:

```text
MediaStorage
├── LocalFilesystem
├── S3-compatible storage
└── future adapters
```

This would make Mediarama suitable for both classic self-hosting and scalable deployments.

The initial release does not need every adapter, but the domain model should avoid hard-coding URLs and filesystem paths as identity.

---

## 8. Media types

Coppermine supports more than still images.

Existing concepts include:

- images;
- video/movie files;
- audio;
- documents;
- configurable MIME/extensions;
- generated thumbnails/preview behavior.

Mediarama should therefore use a **media asset** model, not a `Picture` model.

Suggested conceptual entity:

```text
MediaAsset
- id
- type
- mime_type
- original_filename
- storage_key
- size
- width
- height
- duration
- title
- description
- metadata
- created_at
- uploaded_by
- visibility
```

Exact schema remains to be designed.

---

## 9. Users, groups and permissions

Coppermine has long-standing concepts for:

- administrators;
- registered users;
- anonymous users;
- banned users;
- group quotas;
- upload permissions;
- private albums;
- album visibility.

These are useful functional requirements.

For Mediarama they should be remodeled as explicit authorization capabilities rather than scattered conditionals.

Potential direction:

```text
Role / Group
    |
Permissions
    |
Resource policy
    |
Album / Media visibility
```

No final RBAC/ABAC decision has been made.

---

## 10. Security observations

Coppermine 1.6 continues to receive security fixes, including fixes in 2026.

That is both positive and informative:

- the project is maintained;
- the old architecture still exposes a recurring security-maintenance burden.

The Mediarama design should reduce this burden structurally through:

- framework-provided request handling;
- centralized validation;
- parameterized database access;
- CSRF protection;
- secure session handling;
- output escaping;
- explicit authorization policies;
- media-type validation;
- upload isolation;
- security-focused automated tests.

Security should be part of the foundation milestone rather than a final cleanup phase.

---

## 11. Development tooling

The initial inspection has not identified evidence that Coppermine 1.7 transformed the project into a modern Composer-centric, test-driven application architecture.

A dedicated tooling inventory is still required.

Mediarama should plan for:

- dependency management;
- automated tests;
- lint/static analysis;
- CI;
- database migrations;
- reproducible development environment;
- coding standards;
- security scanning.

---

## 12. What should be preserved from Coppermine

The research so far strongly supports preserving **product knowledge**, particularly:

- albums/categories;
- flexible media/file handling;
- permissions and privacy concepts;
- group quotas;
- multi-file uploads;
- batch ingestion;
- image derivatives/thumbnails;
- metadata;
- search;
- comments and ratings where desired;
- favorites;
- localization;
- plugin/extensibility concept;
- bridges/integration knowledge;
- administrative workflows;
- mature migration expectations.

This does not require retaining Coppermine's architecture.

---

## 13. What should not be inherited by default

Mediarama should avoid carrying forward:

- page-per-script application structure;
- global mutable state;
- SQL scattered through presentation/application scripts;
- HTML generated from domain/application logic;
- table-based layout;
- theme overrides that duplicate large PHP functions;
- database-vendor assumptions throughout the codebase;
- webroot-relative file identity;
- legacy JS dependencies merely for compatibility;
- compatibility layers that have no Mediarama requirement.

---

## 14. Current architecture hypothesis

Based on the first verified comparison, the strongest working hypothesis is:

> Build Mediarama as a new core application, use Coppermine 1.6.x as the primary functional/migration reference, use selected 1.7.x ideas as UX research, and provide a first-class Coppermine importer.

Why 1.6.x as the primary reference?

Because it contains the actively maintained Coppermine behavior and post-2023 security/PHP compatibility work that 1.7.x does not.

Why not fork 1.6.x directly?

Because the principal Mediarama goals — application/presentation separation, UIkit-first UI, clean persistence boundaries and modern application structure — would require replacing too much of the existing architecture.

Why still study 1.7.x?

Because it contains useful experiments around responsive presentation, Theme2, touch/keyboard navigation and cleanup that should inform requirements.

This hypothesis must be formalized later as an Architecture Decision Record after the remaining discovery work.

---

## 15. Next research tasks

Before freezing the architecture:

- [ ] Produce complete Coppermine feature inventory.
- [ ] Compare 1.6 and 1.7 SQL schema field-by-field.
- [ ] Inventory all core tables and relationships.
- [ ] Map album/category/media ownership and visibility.
- [ ] Map upload flows and derivative generation.
- [ ] Map authentication, groups and bridge behavior.
- [ ] Inventory plugin hooks.
- [ ] Inventory metadata/EXIF/IPTC behavior.
- [ ] Inventory media type handling.
- [ ] Inventory search behavior.
- [ ] Inventory comments, ratings, favorites and reporting.
- [ ] Inspect localization architecture.
- [ ] Inspect upgrade/migration process.
- [ ] Assess licensing implications of source reuse vs clean implementation.
- [ ] Evaluate PostgreSQL against the resulting Mediarama domain model.
- [ ] Evaluate target PHP/application framework options.
- [ ] Produce ADR for fork vs new core.
- [ ] Produce ADR for database.
- [ ] Produce ADR for storage abstraction.
- [ ] Convert confirmed requirements into milestones/issues.

---

## Sources inspected

Primary source repositories:

- https://github.com/coppermine-gallery/cpg1.6.x
- https://github.com/coppermine-gallery/cpg1.7.x

Key inspected areas include repository metadata, recent commit history, README files, changelogs, database selectors/drivers, SQL schema locations, plugin API, theme/template code, Theme2 files, upload-related code and representative legacy table markup.

All conclusions in this document should be revisited as the deeper inventory proceeds.
