# ADR-0012: Keep WebP as the sole standard display derivative codec

- Status: Accepted
- Date: 2026-09-27

## Context

Mediarama currently exposes one display derivative per logical profile/version identity:

`media_id + kind + profile + processing_version`

The standard image profiles are:

- `thumbnail`: 480 × 480 maximum, WebP quality 82;
- `preview`: 1600 × 1600 maximum, WebP quality 84;
- `large`: 2560 × 2560 maximum, WebP quality 86.

ADR-0010 moved the production WebP path to the explicit `cwebp` encoder after the ImageMagick 6.9.12-98 WebP-quality regression was detected.

Issue #74 and PR #76 then measured whether JPEG or AVIF should change the product format boundary.

The accepted representative benchmark used:

- 25 GB82 CC0 sources;
- 30 CLIC 2025 final-holdout sources;
- 6 manifest-verified high-resolution PD-own camera photographs from imazen-26;
- 61 sources total;
- 1,896 successful codec samples;
- WebP through the same production `CwebpEncoder`;
- JPEG and AVIF as ImageMagick benchmark candidates;
- Q55/Q70/Q82/Q90 codec curves;
- thumbnail, preview and large bounds where source resolution allowed them;
- machine-readable bytes, encode duration and PSNR measurements;
- retained high-resolution review candidates for visual inspection.

The accepted evidence is retained in:

`docs/research/evidence/derivative-format-benchmark-2026-09-27.json`

The benchmark did not treat equal numeric quality values as equal visual quality. Instead, JPEG and AVIF were interpolated against the measured PSNR of the current production WebP target for each profile when the candidate curve covered that target.

Pooled median size difference versus production WebP at the comparable measured PSNR region was:

| Profile | JPEG | AVIF |
| --- | ---: | ---: |
| thumbnail | +51.11% | -14.14% |
| preview | +48.35% | -15.46% |
| large | +47.60% | -12.10% |

Pooled median encode-time ratio versus WebP was:

| Profile | JPEG | AVIF |
| --- | ---: | ---: |
| thumbnail | 0.523× | 3.119× |
| preview | 0.302× | 2.182× |
| large | 0.295× | 2.008× |

The retained high-resolution visual review did not reveal a systematic codec defect or a decisive perceptual advantage that contradicted the measured curves.

JPEG was consistently faster to encode but materially larger at comparable measured quality.

AVIF was consistently smaller but materially slower to encode.

The current derivative identity and public delivery model resolve one MIME type/output for each logical profile/version. They do not currently model simultaneous WebP and AVIF variants for one logical profile with browser fallback selection.

## Decision

Mediarama keeps `cwebp` WebP as the sole standard display derivative codec for the current single-format profile architecture.

Specifically:

1. The standard `thumbnail`, `preview` and `large` profiles remain WebP.
2. JPEG is not exposed as an alternative display-derivative profile format.
3. AVIF does not replace WebP in the current single-format profile model.
4. No arbitrary user-selectable output-format string is added to the standard derivative configuration.
5. The current production WebP qualities remain 82 / 84 / 86 for thumbnail / preview / large.
6. The existing immutable-original, metadata-stripping, bounded processing and versioned-regeneration guarantees remain unchanged.
7. AVIF may be reconsidered only as a separate architecture change that introduces explicit multi-format derivative variants/fallback semantics and benchmarks the exact production encoder path used by that implementation.

This decision resolves the derivative-format question without adding format configurability that the current delivery identity does not need.

## Why JPEG is not exposed

JPEG's faster encoding does not justify a second standard display format in the current product model.

At the measured comparable-PSNR regions it was approximately 48–51% larger in pooled median output size for the normal Mediarama profiles.

JPEG would also require an explicit alpha/transparency policy for sources whose semantics include transparency. Adding that complexity without a product requirement would broaden the format surface without a measured delivery benefit.

JPEG remains a supported source format and may still be appropriate in future export/download features with their own explicit semantics.

## Why AVIF does not replace WebP now

AVIF showed a real byte-size benefit of roughly 12–15% in the pooled median comparison, but also approximately 2.0–3.1× the WebP encode time in the measured runner.

That benefit is not ignored. It is deferred because the current derivative identity stores one output per logical profile/version.

Replacing WebP outright would trade the already-shipped production path for AVIF without a fallback variant model. Storing both formats under one current identity would be incorrect.

If a later product requirement justifies multi-format responsive delivery, the architecture should first make format/variant an explicit part of derivative identity, URLs, persistence and selection. That implementation must then be benchmarked using its actual production AVIF encoder and settings.

## Quality semantics

Codec quality numbers remain codec-specific.

Mediarama must not infer that JPEG 82, WebP 82 and AVIF 82 represent equal visual quality.

The accepted evidence uses measured curves and interpolation only where the target quality region is covered by the candidate measurements. It does not extrapolate beyond measured curves and does not claim that PSNR alone represents perceptual equivalence.

Future codec changes must repeat this principle.

## Runtime and deployment boundary

The standard display derivative runtime remains:

- ImageMagick for bounded original decoding, orientation normalization, stripping, resizing and optional watermark preparation;
- `cwebp` for the final WebP encode;
- fail-closed behavior when the required encoder is unavailable.

No new JPEG or AVIF production encoder dependency is introduced by this decision.

## Reconsideration triggers

Reopen the format architecture only when at least one concrete product requirement exists, such as:

- multi-format `<picture>` delivery with explicit fallback;
- a material bandwidth/storage target that the current WebP path cannot meet;
- a deployment/runtime change that materially alters the measured WebP/AVIF trade-off;
- a client/device compatibility requirement that needs a second display format;
- a new dedicated AVIF encoder path with substantially different performance.

A reconsideration must include:

- explicit derivative variant identity and cache/URL semantics;
- production encoder capability checks;
- transparency behavior;
- real integration tests;
- representative benchmark evidence using the exact production backend;
- processing-version/migration implications.

## Consequences

Positive:

- the production media pipeline stays simple and deterministic;
- no premature format switch is made from benchmark fashion or matching numeric quality values;
- WebP benchmark evidence uses the same backend that production actually ships;
- JPEG's size penalty and AVIF's encode/deployment cost are documented instead of guessed;
- the data model is not distorted to hide multiple formats under one logical derivative identity;
- future AVIF work has a clear architecture gate rather than an ad-hoc configuration switch.

Costs:

- Mediarama does not immediately realize AVIF's measured byte-size reduction;
- installations that specifically want AVIF display variants must wait for a deliberate multi-format model;
- WebP remains a required runtime dependency through `cwebp`.

## Evidence and related decisions

- ADR-0010 — explicit `cwebp` WebP encoder
- Issue #74 — derivative-format benchmark and decision gate
- PR #76 — accepted representative derivative benchmark evidence
- Issue #6 — image metadata and derivative-processing implementation
- `docs/research/evidence/derivative-format-benchmark-2026-09-27.json`

Accepted benchmark run:

- GitHub Actions run `36329534098`;
- accepted benchmark head `4a30145da6dcb6a04dd730d791d84ba37002fae8`;
- matching full CI run `36329534026`;
- accepted artifact SHA-256 `01985103d60c8680ae872cdba97f0ff73895d4b564dfe61ee4a486d6b742e703`.

PR #76 was subsequently revalidated on its final head before merge.
