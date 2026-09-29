# Coppermine security lessons relevant to Mediarama

Status: **verified source-history audit at requirements level**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current Coppermine 1.6 source;
- 1.6 changelog;
- security-tagged commits from 2016–2026;
- comparison with the dormant 1.7 branch where relevant.

This document intentionally records defensive lessons and product requirements, not exploitation instructions.

## Why current 1.6 matters

Current 1.6 continued receiving security maintenance after 1.7 stopped.

Several verified fixes are directly relevant to Mediarama because they touch:

- upload paths;
- media editing;
- authorization;
- URL rendering;
- helper-file path selection;
- database input validation;
- mail dependencies.

This reinforces the rule:

> never treat 1.7 as automatically safer because its version number is higher.

## 2026: upload/path confinement

Current 1.6.29 added path confinement in chunked upload handling.

Client-controlled values used for chunk/session and filename handling are reduced to a basename before they participate in filesystem path construction.

The 1.7 copy does not contain this later hardening.

### Mediarama requirement

Mediarama should go further:

- upload-session IDs are server-generated;
- chunk index is strictly numeric/ranged;
- storage paths derive from server-controlled IDs;
- original filename is metadata only;
- path separators/traversal forms are never accepted as storage identity;
- temp and permanent roots are resolved/confined;
- tests assert that user input cannot escape those roots.

## 2026: media-edit authorization recheck

The same 1.6.29 security batch added an explicit authorization check in the bulk media-edit POST processing path.

The lesson is broader than that one file:

> showing an edit UI only to an authorized user is not enough; every mutation handler must independently authorize the action.

### Mediarama requirement

Every command/mutation must perform server-side authorization at execution time.

For resource-moving operations, re-evaluate authorization against:

- source resource;
- destination resource;
- requested capability;
- current actor state.

This is particularly important for:

- collection membership changes;
- moderation;
- upload finalization;
- metadata edits;
- derivative regeneration;
- user/account administration.

## 2026: URL-scheme allowlist

Current 1.6.29 changed link processing so a parsed explicit URL scheme is accepted only when it is HTTP or HTTPS.

This is a defensive response to unsafe link/protocol handling.

### Mediarama requirement

For user-generated links and metadata URLs:

- normalize/parse before rendering;
- use an explicit scheme allowlist;
- default to HTTP/HTTPS where clickable URLs are expected;
- never rely on output escaping alone to decide whether a URL is safe;
- keep email/tel/custom protocols as separate explicitly typed fields if supported.

## 2026: language/help path selection

Current 1.6.28/1.6.29 hardened helper pages that selected language/help resources from request values by constraining them to basename values.

This follows the same path-traversal lesson seen in earlier Coppermine history.

### Mediarama requirement

Do not map request strings directly to template/locale/file paths.

Use:

- locale IDs from a server-side registry;
- theme IDs from a server-side registry;
- validated storage/object IDs;
- explicit path resolvers;
- no arbitrary include/template/file path from query parameters.

## 2021: upload help XSS

A security fix in the HTML5 upload plugin escaped request-controlled values before rendering them into the help page.

Later 2026 maintenance additionally constrained path-like parameters with `basename()`.

### Mediarama lesson

Validation and output encoding solve different problems.

For every request-derived value:

1. validate/normalize according to its semantic type;
2. authorize access to the referenced resource;
3. encode according to the output context.

Do not treat HTML escaping as filesystem/path validation.

## 2018: e-card XSS

The e-card path received a security fix changing how posted input is escaped before display/use.

Mediarama is not planning to preserve legacy e-cards as a core feature, but the lesson applies to:

- comments;
- reports;
- captions;
- descriptions;
- imported legacy text;
- notification templates.

### Mediarama requirement

Store canonical user text and perform context-appropriate output encoding.

If rich text is supported:

- define a constrained markup format;
- sanitize to an allowlist;
- do not permit arbitrary stored HTML by default.

## 2017: media-editor directory traversal

A Coppermine security change removed path separators from the accepted temporary/new-image identifier in the image editor.

### Mediarama requirement

Media edit recipes should reference a MediaAsset/storage object by typed ID, never by an arbitrary relative filesystem path provided by the client.

Image processing should operate on resolved server-side storage objects.

## 2016: ImageMagick shell execution

Coppermine historically constructed ImageMagick command strings and later added shell escaping after a security issue.

### Mediarama improvement already chosen

Mediarama uses Symfony Process with argument arrays instead of shell-concatenated commands.

Keep this invariant:

- no shell interpolation for filenames/metadata;
- fixed/controlled executable path;
- explicit argument vector;
- timeout;
- output limit;
- ImageMagick resource policy;
- untrusted-media test fixtures.

This is a strong reason not to transplant Coppermine's image-processing implementation.

## 2016: SQL injection fixes

Historical fixes tightened password-reset identifiers and moderation identifiers before SQL use.

Coppermine's direct-SQL architecture required repeated local validation discipline.

### Mediarama requirement

Use parameterized DBAL/ORM queries throughout application code.

Also use typed identifiers before reaching persistence:

- UUID;
- integer source ID during import;
- enum/policy value;
- normalized email;
- bounded pagination.

Validation remains necessary even when parameterization prevents injection because semantic abuse can still occur.

## Mail dependency history

Coppermine also carried a historical PHPMailer vulnerability fix.

The general lesson is dependency hygiene:

- use maintained mailer/provider packages;
- track security advisories;
- keep dependencies updated;
- do not fork/vendor obsolete security-sensitive libraries unless unavoidable.

## Trust boundary around imported Coppermine data

Migration data must be treated as untrusted even when it came from the operator's own old gallery.

Potentially hostile or malformed values include:

- filenames/paths;
- captions/descriptions;
- URLs;
- serialized favorites/EXIF;
- plugin configuration;
- custom profile/media fields;
- theme/plugin names/paths;
- legacy password hints;
- metadata payloads.

### Import rule

Do not execute source PHP, plugins or themes.

Parse only known data formats through bounded readers.

Do not use unsafe object deserialization for source blobs.

## Serialization lesson

Coppermine stores some state using PHP serialization, including favorites/cookies/metadata-related historical structures.

Mediarama migration should decode only expected primitive structures.

Never instantiate arbitrary serialized classes/objects from the source database or browser state.

## Authentication/session lesson

Do not migrate:

- active sessions;
- remember-me state;
- password-reset tokens;
- activation tokens as credentials;
- old album-password unlock cookies;
- brute-force counters.

Migrate user/account intent and state, then establish fresh Mediarama credentials/sessions.

## Authorization lesson from Coppermine architecture

Coppermine often has authorization logic distributed among page bootstrap, UI rendering and handlers.

Mediarama should centralize policies and still enforce them at each mutation boundary.

Security tests should include:

- user can see UI but mutation is denied after capability changes;
- destination permission changes between upload start and finalize;
- ownership changes between form load and submit;
- private collection remains private through export/search/derivative URLs;
- original-download permission is stricter than preview permission.

## Storage and media processing

From Coppermine's history and Mediarama's threat model, processing must assume malformed input.

Required controls before stable release:

- content-based media allowlist;
- decoder/prober validation;
- decompression-bomb/resource limits;
- maximum dimensions/duration/frame counts where relevant;
- processing timeout;
- temporary storage quotas;
- no executable handling of uploaded active content;
- isolated derivative/output paths;
- idempotent cleanup and orphan reconciliation.

## Imported URLs and outbound requests

Coppermine's URL-scheme fix is one side of the problem.

If Mediarama later fetches remote media/metadata, it also needs server-side request protections:

- explicit protocol allowlist;
- destination/network policy;
- redirects revalidated;
- size/time limits;
- no implicit access to local/private infrastructure.

Ordinary gallery rendering should not perform arbitrary remote fetches from imported URLs.

## Security-sensitive privacy reductions

Mediarama should deliberately avoid carrying forward Coppermine's historical collection of:

- hit IPs;
- vote IP/browser/OS/referrer;
- comment IPs;
- upload/last-hit IPs;
- e-card sender IP;
- old brute-force network state.

These should be migrated only with an explicit retention purpose.

## Mediarama security test matrix derived from the audit

Before beta, add automated coverage for at least:

### Path/storage

- client filename cannot escape root;
- chunk/session identifier cannot control path;
- locale/theme IDs cannot become file paths;
- symlink/path-confusion cases are rejected or safely resolved.

### Authorization

- every write endpoint checks capability;
- collection destination is re-authorized at finalization;
- owner/non-owner edit/delete;
- private/restricted media derivative access;
- original-download access level.

### Output/content

- unsafe URL schemes rejected;
- captions/comments/report messages encoded/sanitized;
- imported legacy text cannot inject markup/script;
- metadata values remain data.

### Process execution

- filenames/metadata do not alter ImageMagick/ExifTool argv structure;
- timeout/resource failure is safe;
- failed job cannot leave an asset falsely marked ready.

### Database/import

- parameterized source/target queries;
- malformed IDs rejected;
- primitive-only legacy serialization decode;
- unknown plugin/theme code is never executed.

### Authentication

- legacy sessions/tokens ignored;
- imported users require fresh credentials;
- inactive/suspended source state does not become active by accident;
- brute-force state is not mistaken for a permanent user ban.

## 1.6 vs 1.7 security conclusion

Verified examples where current 1.6 contains later security hardening absent from the dormant 1.7 branch include:

- chunk/path basename confinement;
- helper-language path confinement;
- bulk edit authorization recheck;
- link-scheme allowlisting;
- later upload/plugin hardening.

Therefore:

> 1.7 is a research source for selected UX/media experiments, while current 1.6 is the required legacy security-maintenance reference.

## Exit condition for this audit area

The Coppermine security-research item can be considered researched when these lessons are reflected in Mediarama requirements/tests.

It does **not** mean the Mediarama security implementation itself is complete.
