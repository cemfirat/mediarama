# Image Processing

Status: foundation implementation

## Principle

The uploaded source is immutable.

All display images are generated derivatives with an explicit profile and processing version.

## Engine

Image preparation uses ImageMagick through Symfony Process.

ImageMagick owns untrusted-source decode, auto-orientation, metadata stripping, fit-inside resizing and watermark composition under the configured resource envelope.

Final WebP encoding uses the WebP project's `cwebp` utility through `CwebpEncoder`. This explicit boundary avoids depending on ImageMagick's format-wrapper quality semantics; the ImageMagick 6.9.12-98 WebP writer is known to ignore the requested lossy quality because of an upstream regression.

ImageMagick and cwebp are infrastructure only; application code depends on `ImageDerivativeGenerator`. The encoder decision is recorded in ADR-0010.

## Profiles

Initial product profiles:

- `thumbnail`: 480 × 480 maximum
- `preview`: 1600 × 1600 maximum
- `large`: 2560 × 2560 maximum

Profiles are fit-inside bounds and never upscale the source.

Default derivative format is WebP. WebP profiles are encoded by `cwebp`; `ImageDerivativeProfile::quality` maps directly to cwebp's lossy `-q` value.

The standard processing-version floor is currently **2** because moving final WebP encoding from ImageMagick to cwebp changes derivative bytes. Output format can become deployment/profile configuration later, after #74 completes its measured format decision.

## Orientation

Derivatives use embedded orientation during decoding and are physically normalized with auto-orient.

The generated derivative therefore does not require the browser to interpret the source orientation metadata.

## Metadata

Display derivatives are stripped of embedded source metadata by default.

Canonical metadata remains in PostgreSQL and the immutable source retains its original embedded metadata.

Metadata-bearing downloads are generated separately through the export pipeline.

This avoids accidentally publishing GPS or other sensitive source metadata through thumbnails/previews.

## Idempotency

A derivative identity is:

`media_id + kind + profile + processing_version`

The worker skips a derivative already recorded for that identity.

Storage keys are deterministic:

`derivatives/{media-id}/v{version}/{profile}.{format}`

Changing processing behavior requires incrementing the configured processing-version floor.

## Explicit regeneration

Normal asynchronous processing remains retry-idempotent: if a derivative already exists for the configured `media_id + kind + profile + processing_version` identity, the worker skips it.

The explicit `mediarama:media:regenerate <media-id>` command has different semantics. For image media it deliberately creates a **new complete versioned derivative set** instead of merely filling missing files:

- a PostgreSQL advisory lock serializes regeneration for the same media/kind pair;
- the target version is `max(configured processing-version floor, latest persisted image version + 1)`;
- every configured image profile is generated first;
- all derivative records are published in one database transaction;
- public gallery/search queries therefore switch from the old version set to the new version set atomically at the database boundary;
- older derivative versions remain addressable, preserving long-lived immutable URLs/caches;
- if generation or batch persistence fails, newly generated storage artifacts are cleaned up and the old persisted version remains authoritative.

The advisory lock is session-scoped and fail-fast. A second concurrent regeneration for the same media/kind is rejected rather than racing for the same deterministic storage keys. A crashed database session releases its advisory lock automatically.


## Watermarks

Watermarking is a derivative-only transformation. It never modifies the immutable source.

The current derivative identity is `media_id + kind + profile + processing_version`, and one derivative can be reused from several Collections. For that reason the first implementation is deliberately **profile-level and deployment-configured**. Collection-, user- or request-specific watermark variants would require an additional derivative policy/variant identity plus matching URL/cache semantics; they must not silently reuse the current profile identity.

A profile opts into watermarking with `ImageDerivativeProfile::watermark=true`. The shared deployment settings are:

- `IMAGE_WATERMARK_ASSET_PATH` — trusted local PNG asset; empty by default;
- `IMAGE_WATERMARK_SIZE_PERCENT` — maximum watermark bounding-box size relative to both dimensions of the actual resized derivative, 1–50;
- `IMAGE_WATERMARK_OPACITY_PERCENT` — overlay opacity, 1–100;
- `IMAGE_WATERMARK_MARGIN_PERCENT` — inset relative to the derivative's shorter side, 0–20;
- `IMAGE_WATERMARK_GRAVITY` — one of `northwest`, `north`, `northeast`, `west`, `center`, `east`, `southwest`, `south`, `southeast`;
- `IMAGE_WATERMARK_THUMBNAIL`, `IMAGE_WATERMARK_PREVIEW`, `IMAGE_WATERMARK_LARGE` — per-standard-profile switches, all `false` by default.

The path is deployment configuration, never request/user input. The configured asset must be a readable, non-empty PNG no larger than 16 MiB or 8192 pixels in either dimension. A watermark-enabled profile fails closed when the asset is missing or invalid.

Rendering avoids lossy double encoding:

1. the source is decoded, auto-oriented, stripped and resized into a temporary lossless MIFF image under the normal ImageMagick resource envelope;
2. actual resized dimensions are inspected;
3. watermark bounding-box dimensions and margin are calculated from those dimensions;
4. the PNG watermark is resized, its alpha channel is multiplied by the configured opacity, and it is composited with validated gravity using the standard `Over` alpha-composition mode;
5. the composited result is written as a stripped lossless PNG;
6. for the current WebP profiles, cwebp performs the single lossy final encode with the profile quality.

The derivative metadata records that watermarking occurred plus the asset/render-configuration fingerprint, gravity, size percentage, opacity percentage and margin percentage. The configured filesystem path is never persisted.

Changing the watermark asset or any rendering setting changes the fingerprint and requires a new processing version/regeneration before existing public derivatives change.

Default thumbnail/preview/large profiles remain unwatermarked. Deployment can enable them individually through the three profile switches above; enabling or changing watermark behavior requires explicit versioned regeneration before existing derivatives change.

Primary ImageMagick behavior references:

- https://imagemagick.org/compose/
- https://imagemagick.org/command-line-options/#composite
- https://usage.imagemagick.org/compose/

## Resource safety

All Mediarama ImageMagick subprocesses use the same finite resource envelope before an input image is read.

Application-level limits are configured through:

- `IMAGEMAGICK_LIMIT_MEMORY`
- `IMAGEMAGICK_LIMIT_MAP`
- `IMAGEMAGICK_LIMIT_DISK`
- `IMAGEMAGICK_LIMIT_AREA`
- `IMAGEMAGICK_LIMIT_WIDTH`
- `IMAGEMAGICK_LIMIT_HEIGHT`
- `IMAGEMAGICK_LIMIT_FILES`
- `IMAGEMAGICK_LIMIT_THREADS`
- `IMAGEMAGICK_LIMIT_TIME_SECONDS`
- `IMAGEMAGICK_LIMIT_LIST_LENGTH`
- `IMAGEMAGICK_PROCESS_TIMEOUT_SECONDS`

The command-line limits are prepended to both geometry inspection and derivative generation, so they are active before ImageMagick reads the untrusted source. `area` accepts either a finite cache-byte value (for example `512MiB`) or a pixel-area value supported by ImageMagick (for example `64MP`). Sequence length is requested through `MAGICK_LIST_LENGTH_LIMIT` where supported; the deployment policy remains the authoritative ceiling for builds that do not expose that environment control.

The Symfony Process timeout remains a second hard stop around the ImageMagick resource-time limit. This is intentional: ImageMagick resource limits primarily control its own pixel-cache/runtime resources, while the parent process must still be able to terminate a command that does not return.

WebP encoding has a separate finite subprocess boundary:

- `CWEBP_BINARY` selects the cwebp executable;
- `CWEBP_PROCESS_TIMEOUT_SECONDS` limits the parent process, 60 seconds by default.

cwebp never receives an uploaded source directly. It receives only a stripped lossless PNG that ImageMagick has already decoded and resized to the bounded derivative profile. cwebp is invoked with explicit RGB quality, method 4, lossless alpha quality and `-metadata none`. Missing or failing cwebp is an operational failure; Mediarama does not silently fall back to ImageMagick WebP encoding.

Production deployments should also install a restrictive ImageMagick `policy.xml` as an upper ceiling. A reviewed example is kept at `config/imagemagick/policy.xml.example`. ImageMagick policy limits cannot be relaxed by a larger command-line value. Version-specific policy keys must only be enabled after checking the deployed ImageMagick version; for example `max-memory-request` is an ImageMagick 7 feature.

The defaults are deliberately finite but are deployment settings rather than universal hardware recommendations. Operators may tighten them for smaller workers or raise them after measurement for unusually large professional images. Width/height, disk and elapsed-time limits must remain finite for Internet-facing installations.

CI runs `tests/Integration/ImageMagick/resource-limits.php` against the actual ImageMagick binaries. It verifies a valid image, an oversized-dimension image, a deliberately truncated image, a highly compressed decode-stress image under tight cache limits, and the invariant that a failed conversion never becomes a persisted derivative.

CI runs `tests/Integration/ImageMagick/cwebp-quality.php` against the actual cwebp binary. A deterministic fixture must produce different encoded bytes at low and high quality, with the higher-quality output materially larger, while the prepared source remains immutable. This prevents a flat/no-op quality curve from returning unnoticed.

CI also runs `tests/Integration/ImageMagick/watermark.php` with a runtime-generated PNG watermark. It verifies dimension-relative placement, transparent-pixel preservation, configured alpha blending, immutable-source preservation, stripped Orientation/GPS metadata, fingerprint recording, and fail-closed behavior for missing/invalid watermark configuration.

References:

- ImageMagick command-line resource limits: https://imagemagick.org/command-line-options/#limit
- ImageMagick security policy: https://imagemagick.org/security-policy/
- ImageMagick legacy/6.x resource model: https://legacy.imagemagick.org/script/resources.php/
- cwebp encoder options: https://developers.google.com/speed/webp/docs/cwebp

## Failure behavior

Derivative failure must leave the MediaAsset in a failed processing state and must not publish a partially processed asset.

A retry with the same processing version is safe because completed profiles are detected and skipped.

## Real-format integration coverage

CI exercises the real derivative generator with actual encoded source files rather than parser-only or mocked media.

The current runtime matrix covers JPEG, PNG, GIF, TIFF, WebP, AVIF, HEIC and HEIF sources and verifies that each can be decoded through the configured ImageMagick runtime, prepared safely and persisted as a bounded cwebp-encoded WebP display derivative.

The same integration gate also verifies a JPEG carrying EXIF orientation 6 and embedded GPS metadata:

- orientation is physically normalized in the generated derivative;
- the immutable source checksum is unchanged;
- display output no longer carries source orientation or GPS metadata after the derivative strip step.

AVIF/HEIC/HEIF source fixtures are encoded independently with libheif so the test does not depend on ImageMagick being able to encode the same input format it is supposed to decode.

The CI runtime installs explicit libheif decoder plugins for both AV1 and HEVC. This is intentional: container support alone is insufficient when the deployed libheif build has codec plugins split into separate packages. Production images advertising AVIF/HEIC/HEIF support must provide equivalent decoders.
