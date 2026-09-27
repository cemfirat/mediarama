#!/usr/bin/env bash
set -euo pipefail

: "${IMAGEMAGICK_BINARY:?IMAGEMAGICK_BINARY must be set}"
: "${IMAGEMAGICK_IDENTIFY_BINARY:?IMAGEMAGICK_IDENTIFY_BINARY must be set}"
: "${CWEBP_BINARY:?CWEBP_BINARY must be set}"

ROOT="$(mktemp -d /tmp/mediarama-format-benchmark-smoke.XXXXXX)"
INPUT="$ROOT/input"
REPORT="$ROOT/report.json"
SUMMARY_JSON="$ROOT/summary.json"
SUMMARY_MD="$ROOT/summary.md"

cleanup() {
  rm -rf "$ROOT"
}
trap cleanup EXIT

mkdir -p "$INPUT"

"$IMAGEMAGICK_BINARY"   -size 640x360   "gradient:#1f2a44-#e5bf8c"   -fill '#f8f8f8'   -draw 'rectangle 40,40 240,160'   -fill '#202020'   -draw 'circle 440,180 510,180'   "$INPUT/smoke-source.png"

php bin/benchmark-derivative-formats   --input-dir="$INPUT"   --output="$REPORT"   --formats=webp,jpeg,avif   --qualities=70,82   --profiles=smoke:320x320   --limit=1   --convert-binary="$IMAGEMAGICK_BINARY"   --identify-binary="$IMAGEMAGICK_IDENTIFY_BINARY"   --compare-binary="${IMAGEMAGICK_COMPARE_BINARY:-compare}" \
  --cwebp-binary="$CWEBP_BINARY" \
  --corpus-id="mediarama-ci-smoke" \
  --corpus-revision="synthetic-v2"

REPORT="$REPORT" INPUT="$INPUT" php <<'PHP'
<?php

declare(strict_types=1);

$reportPath = (string) getenv('REPORT');
$inputPath = (string) getenv('INPUT');

$raw = file_get_contents($reportPath);
if ($raw === false) {
    throw new RuntimeException('Benchmark smoke report was not written.');
}

$report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

function requireBenchmarkSmoke(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

requireBenchmarkSmoke(($report['schema_version'] ?? null) === 2, 'benchmark report schema is versioned');
requireBenchmarkSmoke(count($report['sources'] ?? []) === 1, 'benchmark reports one smoke source');
requireBenchmarkSmoke(
    ($report['corpus']['id'] ?? null) === 'mediarama-ci-smoke'
    && ($report['corpus']['revision'] ?? null) === 'synthetic-v2',
    'benchmark records corpus provenance',
);
requireBenchmarkSmoke(
    ($report['privacy']['source_paths_included'] ?? null) === false,
    'benchmark report declares source paths excluded',
);
requireBenchmarkSmoke(
    ($report['privacy']['embedded_metadata_copied_to_outputs'] ?? null) === false,
    'benchmark report declares stripped benchmark outputs',
);
requireBenchmarkSmoke(
    ($report['capabilities']['webp']['supported'] ?? false) === true,
    'WebP encoder capability is verified',
);
requireBenchmarkSmoke(
    ($report['capabilities']['jpeg']['supported'] ?? false) === true,
    'JPEG encoder capability is verified',
);

$ok = array_values(array_filter(
    $report['samples'] ?? [],
    static fn (array $sample): bool => ($sample['status'] ?? null) === 'ok',
));

$webp = array_values(array_filter(
    $ok,
    static fn (array $sample): bool => ($sample['format'] ?? null) === 'webp',
));
$jpeg = array_values(array_filter(
    $ok,
    static fn (array $sample): bool => ($sample['format'] ?? null) === 'jpeg',
));

requireBenchmarkSmoke(count($webp) === 2, 'WebP quality curve contains both requested samples');
requireBenchmarkSmoke(count($jpeg) === 2, 'JPEG quality curve contains both requested samples');

requireBenchmarkSmoke(
    count(array_unique(array_column($webp, 'byte_size'))) === 2,
    'WebP quality curve changes output size through cwebp',
);
requireBenchmarkSmoke(
    is_string($report['runtime']['cwebp_version'] ?? null)
    && ($report['runtime']['cwebp_version'] ?? '') !== '',
    'benchmark records the cwebp runtime version',
);
requireBenchmarkSmoke(
    ($report['matrix']['encoder_backends']['webp'] ?? null) === 'cwebp',
    'benchmark records cwebp as the WebP encoder backend',
);

foreach ([...$webp, ...$jpeg] as $sample) {
    requireBenchmarkSmoke(($sample['byte_size'] ?? 0) > 0, 'successful sample records output bytes');
    requireBenchmarkSmoke(($sample['encode_duration_ms'] ?? 0) >= 0, 'successful sample records encode duration');
    requireBenchmarkSmoke(($sample['output_width'] ?? 0) <= 320, 'successful sample respects profile width');
    requireBenchmarkSmoke(($sample['output_height'] ?? 0) <= 320, 'successful sample respects profile height');
}

$encodedReport = json_encode($report, JSON_THROW_ON_ERROR);
requireBenchmarkSmoke(
    !str_contains($encodedReport, $inputPath)
    && !str_contains($encodedReport, 'smoke-source.png'),
    'benchmark report does not leak input path or source filename',
);

$avifSupported = (bool) ($report['capabilities']['avif']['supported'] ?? false);
$avifSamples = array_values(array_filter(
    $report['samples'] ?? [],
    static fn (array $sample): bool => ($sample['format'] ?? null) === 'avif',
));

if ($avifSupported) {
    requireBenchmarkSmoke(
        count(array_filter(
            $avifSamples,
            static fn (array $sample): bool => ($sample['status'] ?? null) === 'ok',
        )) === 2,
        'supported AVIF encoder produces requested quality samples',
    );
} else {
    requireBenchmarkSmoke(
        count($avifSamples) === 1
        && ($avifSamples[0]['status'] ?? null) === 'unsupported',
        'unavailable AVIF encoder is reported explicitly rather than guessed',
    );
}

echo "Derivative-format benchmark smoke checks passed.".PHP_EOL;
PHP


php bin/summarize-derivative-format-benchmark \
  --report="smoke:$REPORT" \
  --markdown="$SUMMARY_MD" \
  --json="$SUMMARY_JSON"

SUMMARY_JSON="$SUMMARY_JSON" SUMMARY_MD="$SUMMARY_MD" php <<'PHP'
<?php

declare(strict_types=1);

$summary = json_decode(
    (string) file_get_contents((string) getenv('SUMMARY_JSON')),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$markdown = (string) file_get_contents((string) getenv('SUMMARY_MD'));

if (($summary['schema_version'] ?? null) !== 2) {
    throw new RuntimeException('Benchmark summary schema is not versioned.');
}
if (count($summary['reports'] ?? []) !== 1) {
    throw new RuntimeException('Benchmark summary did not retain one source report.');
}
if (($summary['reports'][0]['corpus']['id'] ?? null) !== 'mediarama-ci-smoke') {
    throw new RuntimeException('Benchmark summary lost corpus provenance.');
}

$aggregates = $summary['aggregates'] ?? [];
$webp = array_values(array_filter(
    $aggregates,
    static fn (array $row): bool => ($row['format'] ?? null) === 'webp',
));
$jpeg = array_values(array_filter(
    $aggregates,
    static fn (array $row): bool => ($row['format'] ?? null) === 'jpeg',
));

if (count($webp) !== 2 || count($jpeg) !== 2) {
    throw new RuntimeException('Benchmark summary lost WebP/JPEG quality curve rows.');
}
foreach ($webp as $row) {
    if (($row['encoder_backend'] ?? null) !== 'cwebp') {
        throw new RuntimeException('Benchmark summary lost the production WebP encoder backend.');
    }
}
if (!str_contains($markdown, 'Mediarama derivative-format benchmark')
    || !str_contains($markdown, 'cwebp')
    || !str_contains($markdown, 'WEBP')
    || !str_contains($markdown, 'JPEG')) {
    throw new RuntimeException('Benchmark Markdown summary is incomplete.');
}

echo "Derivative-format benchmark summary checks passed.".PHP_EOL;
PHP
