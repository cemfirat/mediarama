# Mediarama licensing and provenance policy

Status: **project policy**
Date: 2026-09-26

## Project license

Mediarama Gallery is licensed **GPL-3.0-or-later**.

The repository carries the complete GNU GPL version 3 license text in `LICENSE`, while package metadata declares the project choice as `GPL-3.0-or-later`.

## Relationship to Coppermine

Coppermine 1.6.x and 1.7.x are functional, migration and architecture reference sources. Both audited upstream lines carry GPLv3 license text.

The current Mediarama technical provenance audit found no copied/adapted Coppermine runtime implementation in Mediarama application code. Source table/field names, constants needed to interpret the source format and behavior descriptions are treated as interoperability facts.

Synthetic migration fixtures are documented in `tests/Fixtures/Coppermine/README.md`.

## Deliberate future source reuse

If Coppermine or other third-party implementation code is deliberately copied or adapted in the future, the change must record:

- upstream project and exact file;
- upstream commit SHA/version;
- upstream license;
- original copyright notice(s);
- copied/adapted range or component;
- Mediarama destination;
- nature of modifications;
- reason direct reuse was chosen.

Required notices and license obligations must remain attached to that material. A compatible repository license is not a substitute for provenance records.

## Dependencies

Composer and npm dependencies remain under their own licenses.

The repository does not vendor `vendor/` or `node_modules/`, and generated `public/build/` output is ignored. Mediarama's project license does not rewrite dependency licenses.

## Fixtures and imported data

Generated synthetic fixtures may be committed when their generation/provenance is documented.

A real or anonymized gallery fixture must not be committed without a documented source, permission to use it, anonymization process and retained-data scope.

Content imported at runtime is data handled by the application; it is not relicensed merely by being processed by Mediarama.

## Review boundary

This document records the project's technical licensing/provenance policy. It is not legal advice.
