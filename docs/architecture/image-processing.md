# Image Processing

Status: foundation implementation

## Principle

The uploaded source is immutable.

All display images are generated derivatives with an explicit profile and processing version.

## Engine

The first image adapter uses ImageMagick through Symfony Process.

ImageMagick is infrastructure only; application code depends on `ImageDerivativeGenerator`.

## Profiles

Initial product profiles:

- `thumbnail`: 480 × 480 maximum
- `preview`: 1600 × 1600 maximum
- `large`: 2560 × 2560 maximum

Profiles are fit-inside bounds and never upscale the source.

Default derivative format is WebP. This can become deployment/profile configuration later.

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
- `IMAGE_WATERMARK_WIDTH_PERCENT` — width relative to the actual resized derivative, 1–50;
- `IMAGE_WATERMARK_OPACITY_PERCENT` — overlay opacity, 1–100;
- `IMAGE_WATERMARK_MARGIN_PERCENT` — inset relative to the derivative's shorter side, 0–20;
- `IMAGE_WATERMARK_GRAVITY` — one of north-west/north/north-east/west/center/east/south-west/south/south-east using the compact configuration names documented by `ImageWatermarkConfiguration`.

The path is deployment configuration, never request/user input. The configured asset must be a readable, non-empty PNG no larger than 16 MiB or 8192 pixels in either dimension. A watermark-enabled profile fails closed when the asset is missing or invalid.

Rendering avoids lossy double encoding:

1. the source is decoded, auto-oriented, stripped and resized into a temporary lossless MIFF image under the normal ImageMagick resource envelope;
2. actual resized dimensions are inspected;
3. watermark pixel width and margin are calculated from those dimensions;
4. the PNG watermark is resized, its alpha channel is multiplied by the configured opacity, and it is composited with validated gravity using the standard `Over` alpha-composition mode;
5. metadata is stripped again and the requested derivative format is encoded once.

The derivative metadata records that watermarking occurred plus the asset/render-configuration fingerprint, gravity, width percentage, opacity percentage and margin percentage. The configured filesystem path is never persisted.

Changing the watermark asset or any rendering setting changes the fingerprint and requires a new processing version/regeneration before existing public derivatives change.

Default thumbnail/preview/large profiles remain unwatermarked until a profile is explicitly configured otherwise.

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

Production deployments should also install a restrictive ImageMagick `policy.xml` as an upper ceiling. A reviewed example is kept at `config/imagemagick/policy.xml.example`. ImageMagick policy limits cannot be relaxed by a larger command-line value. Version-specific policy keys must only be enabled after checking the deployed ImageMagick version; for example `max-memory-request` is an ImageMagick 7 feature.

The defaults are deliberately finite but are deployment settings rather than universal hardware recommendations. Operators may tighten them for smaller workers or raise them after measurement for unusually large professional images. Width/height, disk and elapsed-time limits must remain finite for Internet-facing installations.

CI runs `tests/Integration/ImageMagick/resource-limits.php` against the actual ImageMagick binaries. It verifies a valid image, an oversized-dimension image, a deliberately truncated image, a highly compressed decode-stress image under tight cache limits, and the invariant that a failed conversion never becomes a persisted derivative.

CI also runs `tests/Integration/ImageMagick/watermark.php` with a runtime-generated PNG watermark. It verifies dimension-relative placement, transparent-pixel preservation, configured alpha blending, immutable-source preservation, stripped Orientation/GPS metadata, fingerprint recording, and fail-closed behavior for missing/invalid watermark configuration.

References:

- ImageMagick command-line resource limits: https://imagemagick.org/command-line-options/#limit
- ImageMagick security policy: https://imagemagick.org/security-policy/
- ImageMagick legacy/6.x resource model: https://legacy.imagemagick.org/script/resources.php/

## Failure behavior

Derivative failure must leave the MediaAsset in a failed processing state and must not publish a partially processed asset.

A retry with the same processing version is safe because completed profiles are detected and skipped.

## Real-format integration coverage

CI exercises the real derivative generator with actual encoded source files rather than parser-only or mocked media.

The current runtime matrix covers JPEG, PNG, GIF, TIFF, WebP, AVIF, HEIC and HEIF sources and verifies that each can be decoded through the configured ImageMagick runtime and persisted as a bounded WebP display derivative.

The same integration gate also verifies a JPEG carrying EXIF orientation 6 and embedded GPS metadata:

- orientation is physically normalized in the generated derivative;
- the immutable source checksum is unchanged;
- display output no longer carries source orientation or GPS metadata after the derivative strip step.

AVIF/HEIC/HEIF source fixtures are encoded independently with libheif so the test does not depend on ImageMagick being able to encode the same input format it is supposed to decode.

The CI runtime installs explicit libheif decoder plugins for both AV1 and HEVC. This is intentional: container support alone is insufficient when the deployed libheif build has codec plugins split into separate packages. Production images advertising AVIF/HEIC/HEIF support must provide equivalent decoders.
