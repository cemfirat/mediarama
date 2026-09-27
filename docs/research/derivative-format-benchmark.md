# Derivative Format Benchmark

Status: benchmark tooling; no production format decision yet

Related issue: #74

## Purpose

Mediarama currently generates WebP display derivatives.

JPEG or AVIF must not be exposed merely because a file extension is recognized or because ImageMagick can decode that format. Encoder availability is runtime/delegate dependent, and numeric `quality` values do not represent equivalent visual quality across codecs.

This benchmark exists to collect repeatable evidence before changing production profiles.

## Primary-source constraints

ImageMagick documents that supported formats can depend on optional delegate libraries and recommends inspecting the formats actually available in the deployed runtime with `identify -list format`:

- https://imagemagick.org/formats/

WebP is measured through Mediarama's production `cwebp` backend, not through ImageMagick's WebP writer. cwebp defines `-q 0..100` directly for lossy RGB quality, while its other encoder controls remain codec-specific:

- https://developers.google.com/speed/webp/docs/cwebp

This distinction is deliberate. Representative run #1 exposed that ImageMagick 6.9.12-98 can encode WebP successfully while ignoring the requested lossy quality. Benchmark and production WebP paths must therefore share the explicit cwebp adapter.

ImageMagick's compare tool supports objective distortion metrics including PSNR, and current ImageMagick documentation also defines SSIM and its tuning parameters. The runtime's available metrics can differ, so the harness detects them rather than assuming them:

- https://imagemagick.org/compare/
- https://imagemagick.org/command-line-options/
- https://imagemagick.org/defines/

Objective metrics are evidence, not a substitute for visual review. The final product decision must combine measured byte size, encode cost, objective quality curves, representative visual inspection and deployment/browser implications.

## Harness

Run:

```bash
php bin/benchmark-derivative-formats \
  --input-dir=/path/to/reviewed-photo-corpus \
  --output=var/benchmarks/derivative-formats.json
```

Default matrix:

- formats: WebP, JPEG, AVIF;
- quality samples: 55, 70, 82, 90;
- profiles:
  - thumbnail 480 × 480;
  - preview 1600 × 1600;
  - large 2560 × 2560.

Override example:

```bash
php bin/benchmark-derivative-formats \
  --input-dir=/data/mediarama-benchmark-corpus \
  --output=var/benchmarks/formats-2026-09.json \
  --formats=webp,jpeg,avif \
  --qualities=45,55,65,75,82,90,95 \
  --profiles=thumbnail:480x480,preview:1600x1600,large:2560x2560
```

Use `--help` for the complete CLI surface.

## What the harness does

For every source it:

1. computes SHA-256 and creates a stable temporary source snapshot;
2. uses only the checksum-derived source ID in the report;
3. creates a stripped, auto-oriented, fit-inside lossless PNG reference per profile;
4. probes each requested encoder with a real write rather than inferring capability from an extension;
5. routes WebP through Mediarama's `CwebpEncoder`, matching the production derivative backend;
6. encodes every supported format across the requested quality curve;
7. records output bytes, actual output dimensions and wall-clock encode duration;
8. records SSIM and PSNR when the installed ImageMagick compare runtime exposes them;
9. records the cwebp runtime version;
10. deletes all temporary benchmark images after the report is written.

The same finite ImageMagick resource envelope used by the application is applied to benchmark preparation and comparison processes. WebP encoding receives only the bounded lossless PNG reference and uses the same cwebp subprocess timeout/backend as production.

## Report privacy

The JSON report intentionally excludes:

- source paths;
- source filenames;
- embedded EXIF/IPTC/XMP;
- generated benchmark image payloads.

It records:

- source SHA-256 / short source ID;
- source format and dimensions;
- profile;
- codec;
- quality sample;
- output bytes;
- encode duration;
- output dimensions/format;
- SSIM/PSNR when available;
- explicit encoder capability/failure information.

The corpus itself remains outside Git.

## Quality interpretation

Do **not** compare rows such as WebP quality 82 and JPEG quality 82 as equal-quality encodes.

Treat each codec's quality samples as a curve. Useful comparisons are of the form:

- which codec reaches a similar SSIM/PSNR region at fewer bytes;
- what encode-time penalty is paid for that reduction;
- whether visual inspection agrees on difficult photos;
- whether the operational dependency is justified.

A final codec setting should be chosen from comparable-quality regions, not from matching quality numbers.

## Representative corpus gate

The CI smoke fixture proves that the harness executes and reports correctly. It is deliberately not product evidence.

The smoke gate also requires the requested WebP quality points to produce a non-flat output-size curve. This is a regression sentinel for the exact class of silent no-op quality failure discovered in ImageMagick 6.9.12-98.

Before closing #74, run the harness on a reviewed, legally usable photo corpus that includes at minimum:

- faces and skin tones;
- foliage/high-frequency detail;
- low-light/noise;
- smooth gradients/skies;
- architecture/edges/textures;
- mixed graphic/photo content;
- portrait and landscape orientation.

Do not commit private or customer photographs.

## AVIF rule

AVIF input decoding does not prove AVIF output encoding.

The harness probes AVIF output independently. If the deployed ImageMagick runtime cannot produce a real AVIF file, the report records the encoder as unsupported. Mediarama must not expose an AVIF derivative profile until there is an explicit, production-supported encoder path with matching runtime tests and deployment documentation.

## Decision gate

Do not change current public profile formats from this benchmark branch.

A later decision change must include:

1. reviewed representative-corpus report;
2. documented codec/quality choice;
3. validated output-format capability boundary in application code;
4. runtime integration coverage for every exposed output codec;
5. unchanged immutable-original, metadata-stripping, resource-limit and versioned-regeneration guarantees.
