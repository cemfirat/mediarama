# Coppermine migration fixtures

These files exist only to test Mediarama's Coppermine migration compatibility.

## SQL fixtures

- `1.6-minimal.sql` is a hand-assembled minimal schema/data set based on verified Coppermine 1.6 table and configuration facts.
- `1.7-variation.sql` adds only the verified 1.7 capabilities needed by the migration test.

They are not database dumps and intentionally avoid upstream comments, bundled content and unnecessary seed data.

Primary model references:

- `coppermine-gallery/cpg1.6.x` — `sql/schema.sql`, `sql/basic.sql`
- `coppermine-gallery/cpg1.7.x` — schema/update sources

Both upstream lines carry GPLv3 license text. Mediarama is currently declared `GPL-3.0-or-later`.

## Media fixtures

Image fixtures are generated during CI with ImageMagick and populated with synthetic metadata via ExifTool.

Audio/video fixtures are generated during CI with FFmpeg. No binary Coppermine media, theme assets or sample gallery content is stored in this directory.

A future real/anonymized migration fixture must document its own source, permission to use it, anonymization process and retained-data scope before it is committed.
