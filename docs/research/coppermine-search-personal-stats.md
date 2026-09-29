# Coppermine search, personal features and statistics audit

Status: **active audit**
Date: 2026-09-25
Tracking: #11

Primary source: current Coppermine 1.6 code.

## Search

Coppermine search is more expressive than a simple title/caption text box.

The search form and `include/search.inc.php` support selecting/searching:

- title
- caption/description
- keywords
- filename
- four custom media fields (`user1` … `user4`)
- uploader/owner name when the user database can be joined
- uploader IP fields for administrators
- album title
- category title
- newer-than N days
- older-than N days

Search behavior includes:

- AND / OR word combination;
- quoted phrases;
- wildcard conversion using `*`;
- a regex mode;
- field-specific search selection;
- visibility filtering through Coppermine's forbidden/restricted album set;
- only approved files in ordinary results.

### Mediarama implication

Mediarama's current PostgreSQL search foundation is useful but is **not yet equivalent** to the mature search surface.

The Mediarama roadmap should explicitly decide on:

- searchable field selection versus a unified relevance search;
- phrase semantics;
- AND/OR behavior;
- wildcard/prefix behavior;
- advanced/regex search — likely not public by default;
- collection/category search;
- date/age filters;
- owner/uploader filters;
- custom metadata fields.

Administrative IP search should not be copied by default because Mediarama is intentionally minimizing historical/network personal data.

## Meta albums / virtual result views

Coppermine treats multiple computed result sets as album-like views. Confirmed examples include:

- random files;
- search results;
- last updated albums;
- favorites;
- browse by upload date;
- last additions;
- last comments;
- last comments by user;
- last uploads by user;
- most viewed;
- top rated;
- last viewed.

These are product behaviors, not necessarily persistent albums.

### Mediarama direction

These should be modeled as queries/saved views/feeds rather than fake persisted collections unless a specific UX requires persistence.

Likely high-value Mediarama views:

- recent uploads;
- recent captures;
- most viewed, if analytics/view counts are implemented;
- top rated, if ratings remain a first-class feature;
- favorites;
- browse by date;
- user/creator views.

Random media should be evaluated as a discovery feature rather than copied mechanically.

## Favorites

Coppermine's favorites behavior is confirmed as a first-class personal feature.

Authenticated favorites:

- are stored in `favpics.user_favpics`;
- contain a serialized/base64-encoded PID list;
- are loaded at initialization;
- are exposed through the `favpics` meta album.

Anonymous favorites:

- are stored in a browser cookie;
- require cookies;
- cannot be reliably transferred by a server-side migration without client participation.

The favorites view re-applies current access restrictions, so a favorite does not bypass a later permission change.

### Mediarama implication

Mediarama's normalized favorites table is the stronger model.

Migration still needs:

- safe decoding with no object deserialization;
- source PID→MediaAsset mapping;
- source user→Mediarama user mapping;
- inaccessible/deleted PID reporting;
- idempotent inserts.

Anonymous cookie favorites should be documented as a migration limitation unless an explicit browser-assisted transfer is built.

## ZIP download

Coppermine has a `zipdownload.php` flow tied to the current favorites set.

Confirmed behavior:

- can be enabled/disabled by configuration;
- collects approved favorite originals;
- creates a temporary ZIP under the gallery files area;
- optional mode adds a generated README/copyright file;
- redirects the browser to the generated archive.

### Mediarama implication

Bulk download is a real mature user outcome worth retaining, but the implementation should differ:

- explicit download selection;
- authorization at export time;
- background export job for large sets;
- export profile for metadata/privacy;
- ephemeral/private archive storage;
- signed/expiring delivery;
- auditability and quotas.

Mediarama's metadata-export architecture can become part of this instead of reproducing Coppermine's temporary webroot ZIP.

## Browse by date

`calendar.php` queries approved media by upload timestamp (`ctime`) and displays days with available files.

Important distinction:

- Coppermine's date browser is based on **upload date**, not necessarily EXIF capture date.

Mediarama can improve this by making the dimension explicit:

- captured date;
- imported/uploaded date;
- modified/published date.

## Hit and vote statistics

Coppermine can persist detailed hit statistics including:

- media identifier;
- IP;
- search phrase;
- timestamp;
- referrer;
- browser;
- OS;
- user ID.

Detailed vote statistics similarly contain rating plus network/client context.

`stat_details.php` exposes sorting/filtering/detail views and administration such as clearing stored hit stats.

### Mediarama implication

The useful product outcomes are:

- views/popularity;
- aggregate trends;
- perhaps referrer/search analytics when explicitly enabled.

The detailed historical IP/browser/OS model should **not** be copied by default. Mediarama should use privacy-conscious analytics with an explicit retention model.

## Report file/comment

Coppermine has a report-to-admin workflow for both media and comments.

Confirmed behavior includes:

- feature flag `report_post`;
- sender identity/email;
- subject/message;
- predefined reason selection;
- file/comment context;
- email delivery to administration;
- admin report display link/payload.

The implementation is coupled to the e-card/mail capability checks.

### Mediarama implication

The product capability is valuable and should be separated into a first-class moderation/report model:

- report target: media/comment/user/collection;
- reason code + optional message;
- authenticated/guest policy;
- moderation queue;
- status/resolution;
- notification policy;
- no opaque serialized report payload in a URL.

This is a strong example where feature semantics should be retained but implementation replaced.
