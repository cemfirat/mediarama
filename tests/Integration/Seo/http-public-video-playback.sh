#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"
: "${PUBLIC_BASE_URL:?PUBLIC_BASE_URL must be set}"
: "${FFMPEG_BINARY:?FFMPEG_BINARY must be set}"

BASE_URL="http://127.0.0.1:8091"
CANONICAL_ORIGIN="${PUBLIC_BASE_URL%/}"
HOSTILE_HOST="video-attacker.example"
SERVER_PID=""
MEDIA_ID="$(php -r 'require "vendor/autoload.php"; echo Symfony\Component\Uid\Uuid::v7()->toRfc4122();')"
COLLECTION_ID="$(php -r 'require "vendor/autoload.php"; echo Symfony\Component\Uid\Uuid::v7()->toRfc4122();')"
SOURCE_TMP="/tmp/mediarama-public-video-${MEDIA_ID}.mp4"
DERIVATIVE_ROOT="${MEDIA_STORAGE_PATH%/}/derivatives/${MEDIA_ID}/v1"
ORIGINAL_ROOT="${MEDIA_STORAGE_PATH%/}/originals/${MEDIA_ID}"
ORIGINAL_PATH="$ORIGINAL_ROOT/source"
POSTER_PATH="$DERIVATIVE_ROOT/poster.jpg"
PLAYBACK_PATH="$DERIVATIVE_ROOT/browser_mp4.mp4"
CANONICAL_URL="$CANONICAL_ORIGIN/media/$MEDIA_ID"
POSTER_URL="$CANONICAL_ORIGIN/media/$MEDIA_ID/video/v1/poster"
PLAYBACK_URL="$CANONICAL_ORIGIN/media/$MEDIA_ID/video/v1/browser_mp4"
PUBLICATION_DATE="2024-02-03T04:05:06+00:00"

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" PUBLIC_VIDEO_COLLECTION_ID="$COLLECTION_ID" php <<'PHP' >/dev/null 2>&1 || true
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$media = (string) getenv('PUBLIC_VIDEO_MEDIA_ID');
$collection = (string) getenv('PUBLIC_VIDEO_COLLECTION_ID');

if ($collection !== '') {
    $db->executeStatement('DELETE FROM collections WHERE id = :id', ['id' => $collection]);
}
if ($media !== '') {
    $db->executeStatement('DELETE FROM media_assets WHERE id = :id', ['id' => $media]);
}
$db->close();
PHP

    php bin/console mediarama:platform:deployment-profile private_workspace >/dev/null 2>&1 || true
    rm -f "$SOURCE_TMP"
    rm -rf "${MEDIA_STORAGE_PATH%/}/derivatives/$MEDIA_ID"
    rm -rf "$ORIGINAL_ROOT"
}
trap cleanup EXIT

mkdir -p "$DERIVATIVE_ROOT" "$ORIGINAL_ROOT"

"$FFMPEG_BINARY" -hide_banner -loglevel error -y     -f lavfi -i "color=c=green:s=320x180:r=25:d=1"     -f lavfi -i "sine=frequency=440:sample_rate=44100:duration=1"     -shortest     -c:v libx264 -pix_fmt yuv420p     -c:a aac -movflags +faststart     "$SOURCE_TMP"

cp "$SOURCE_TMP" "$ORIGINAL_PATH"

"$FFMPEG_BINARY" -hide_banner -loglevel error -y     -i "$SOURCE_TMP"     -map 0:v:0 -frames:v 1 -an -sn -dn -map_metadata -1     -q:v 3     "$POSTER_PATH"

"$FFMPEG_BINARY" -hide_banner -loglevel error -y     -i "$SOURCE_TMP"     -map 0:v:0 -map '0:a:0?' -sn -dn -map_metadata -1     -c:v libx264 -preset medium -crf 23 -pix_fmt yuv420p     -c:a aac -b:a 128k -movflags +faststart -f mp4     "$PLAYBACK_PATH"

SOURCE_SIZE="$(wc -c < "$SOURCE_TMP" | tr -d ' ')"
POSTER_SIZE="$(wc -c < "$POSTER_PATH" | tr -d ' ')"
PLAYBACK_SIZE="$(wc -c < "$PLAYBACK_PATH" | tr -d ' ')"
SOURCE_SHA="$(sha256sum "$SOURCE_TMP" | awk '{print $1}')"

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" PUBLIC_VIDEO_COLLECTION_ID="$COLLECTION_ID" PUBLIC_VIDEO_SOURCE_SIZE="$SOURCE_SIZE" PUBLIC_VIDEO_POSTER_SIZE="$POSTER_SIZE" PUBLIC_VIDEO_PLAYBACK_SIZE="$PLAYBACK_SIZE" PUBLIC_VIDEO_SOURCE_SHA="$SOURCE_SHA" PUBLIC_VIDEO_PUBLISHED_AT="$PUBLICATION_DATE" php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Uid\Uuid;

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$mediaId = (string) getenv('PUBLIC_VIDEO_MEDIA_ID');
$collectionId = (string) getenv('PUBLIC_VIDEO_COLLECTION_ID');
$now = (new DateTimeImmutable())->format(DATE_ATOM);
$publishedAt = (string) getenv('PUBLIC_VIDEO_PUBLISHED_AT');

$db->insert('media_assets', [
    'id' => $mediaId,
    'owner_id' => null,
    'storage_disk' => 'media',
    'storage_key' => 'originals/'.$mediaId.'/source',
    'original_filename' => 'PRIVATE-video-source-sentinel.mov',
    'mime_type' => 'video/mp4',
    'media_type' => 'video',
    'byte_size' => (int) getenv('PUBLIC_VIDEO_SOURCE_SIZE'),
    'checksum_sha256' => (string) getenv('PUBLIC_VIDEO_SOURCE_SHA'),
    'width' => 320,
    'height' => 180,
    'duration_ms' => 1000,
    'title' => 'Public Video Fixture',
    'description' => 'Public video playback integration fixture.',
    'captured_at' => null,
    'processing_state' => 'ready',
    'moderation_state' => 'published',
    'metadata' => json_encode([
        'PRIVATE_RAW_METADATA_SENTINEL' => 'PRIVATE GPS 48.123 16.456',
    ], JSON_THROW_ON_ERROR),
    'metadata_provenance' => '{}',
    'creator' => null,
    'copyright' => null,
    'camera_make' => null,
    'camera_model' => null,
    'lens' => null,
    'iso' => null,
    'aperture' => null,
    'exposure_time' => null,
    'focal_length' => null,
    'latitude' => 48.123,
    'longitude' => 16.456,
    'location_name' => 'PRIVATE LOCATION SENTINEL',
    'search_index_policy' => 'inherit',
    'public_published_at' => $publishedAt,
    'public_updated_at' => $publishedAt,
    'public_published_origin' => 'imported',
    'public_published_source' => 'PRIVATE_IMPORT_SOURCE_SENTINEL',
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => null,
]);

$db->insert('collections', [
    'id' => $collectionId,
    'owner_id' => null,
    'parent_id' => null,
    'cover_media_id' => null,
    'slug' => null,
    'title' => 'Public Video Collection Fixture',
    'description' => null,
    'visibility' => 'public',
    'position' => 999,
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => null,
    'password_protected' => false,
    'password_hash' => null,
    'password_hint' => null,
    'password_reset_required' => false,
    'search_index_policy' => 'inherit',
], [
    'password_protected' => ParameterType::BOOLEAN,
    'password_reset_required' => ParameterType::BOOLEAN,
]);

$db->insert('collection_media', [
    'collection_id' => $collectionId,
    'media_id' => $mediaId,
    'position' => 0,
    'added_by' => null,
    'created_at' => $now,
]);

foreach ([
    [
        'profile' => 'poster',
        'mime' => 'image/jpeg',
        'bytes' => (int) getenv('PUBLIC_VIDEO_POSTER_SIZE'),
        'duration' => null,
        'key' => 'derivatives/'.$mediaId.'/v1/poster.jpg',
        'metadata' => ['role' => 'poster'],
    ],
    [
        'profile' => 'browser_mp4',
        'mime' => 'video/mp4',
        'bytes' => (int) getenv('PUBLIC_VIDEO_PLAYBACK_SIZE'),
        'duration' => 1000,
        'key' => 'derivatives/'.$mediaId.'/v1/browser_mp4.mp4',
        'metadata' => ['role' => 'browser_playback', 'has_audio' => true],
    ],
] as $row) {
    $db->insert('media_derivatives', [
        'id' => Uuid::v7()->toRfc4122(),
        'media_id' => $mediaId,
        'kind' => 'video',
        'profile' => $row['profile'],
        'processing_version' => 1,
        'storage_disk' => 'media',
        'storage_key' => $row['key'],
        'mime_type' => $row['mime'],
        'byte_size' => $row['bytes'],
        'width' => 320,
        'height' => 180,
        'duration_ms' => $row['duration'],
        'metadata' => json_encode($row['metadata'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

$db->close();
PHP

php bin/console mediarama:platform:deployment-profile public_publishing >/tmp/public-video-profile.txt

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8091 -t public public/index.php >/tmp/mediarama-public-video-http.log 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
    if curl --silent --show-error --header "Host: $HOSTILE_HOST" "$BASE_URL/collections" >/dev/null 2>&1; then
        break
    fi
    sleep 0.2
done

fetch_page() {
    local path="$1"
    local body="$2"
    local headers="$3"

    if ! curl --fail --silent --show-error         --header "Host: $HOSTILE_HOST"         --dump-header "$headers"         "$BASE_URL$path"         -o "$body"; then
        cat /tmp/mediarama-public-video-http.log || true
        exit 1
    fi
}

expect_status() {
    local expected="$1"
    local path="$2"
    local body="$3"
    local headers="$4"
    shift 4

    local status
    status="$(curl --silent --show-error         --header "Host: $HOSTILE_HOST"         "$@"         --dump-header "$headers"         --output "$body"         --write-out '%{http_code}'         "$BASE_URL$path")"

    if [ "$status" != "$expected" ]; then
        echo "FAIL expected HTTP $expected but got $status for $path"
        cat /tmp/mediarama-public-video-http.log || true
        cat "$headers" || true
        cat "$body" || true
        exit 1
    fi

    echo "OK HTTP $expected $path"
}

expect_contains() {
    local file="$1"
    local value="$2"
    local label="$3"

    if ! grep -F "$value" "$file" >/dev/null; then
        echo "FAIL $label"
        echo "Expected: $value"
        cat "$file"
        exit 1
    fi

    echo "OK $label"
}

expect_absent() {
    local file="$1"
    local value="$2"
    local label="$3"

    if grep -F "$value" "$file" >/dev/null; then
        echo "FAIL $label"
        echo "Unexpected: $value"
        cat "$file"
        exit 1
    fi

    echo "OK $label"
}

expect_marker_url() {
    local file="$1"
    local marker="$2"
    local url="$3"
    local label="$4"

    if ! grep -F "$marker" "$file" | grep -F "$url" >/dev/null; then
        echo "FAIL $label"
        echo "Expected marker: $marker"
        echo "Expected URL: $url"
        cat "$file"
        exit 1
    fi

    echo "OK $label"
}

assert_video_object() {
    local file="$1"

    PUBLIC_VIDEO_HTML_FILE="$file" \
    PUBLIC_VIDEO_CANONICAL="$CANONICAL_URL" \
    PUBLIC_VIDEO_POSTER="$POSTER_URL" \
    PUBLIC_VIDEO_CONTENT="$PLAYBACK_URL" \
    PUBLIC_VIDEO_PUBLISHED_AT="$PUBLICATION_DATE" \
    php <<'PHP'
<?php
$html = (string) file_get_contents((string) getenv('PUBLIC_VIDEO_HTML_FILE'));
if (!preg_match('~<script type="application/ld\\+json">(.*?)</script>~s', $html, $match)) {
    fwrite(STDERR, "VideoObject JSON-LD script missing.\n");
    exit(1);
}

$data = json_decode(trim($match[1]), true, flags: JSON_THROW_ON_ERROR);
$expected = [
    '@context' => 'https://schema.org',
    '@type' => 'VideoObject',
    'name' => 'Public Video Fixture',
    'description' => 'Public video playback integration fixture.',
    'thumbnailUrl' => (string) getenv('PUBLIC_VIDEO_POSTER'),
    'uploadDate' => (string) getenv('PUBLIC_VIDEO_PUBLISHED_AT'),
    'contentUrl' => (string) getenv('PUBLIC_VIDEO_CONTENT'),
    'mainEntityOfPage' => (string) getenv('PUBLIC_VIDEO_CANONICAL'),
    'duration' => 'PT1S',
];

foreach ($expected as $key => $value) {
    if (($data[$key] ?? null) !== $value) {
        fwrite(STDERR, "Unexpected VideoObject field ".$key.".\n");
        exit(1);
    }
}

$raw = (string) $match[1];
foreach ([
    'PRIVATE-video-source-sentinel.mov',
    'PRIVATE_RAW_METADATA_SENTINEL',
    'PRIVATE LOCATION SENTINEL',
    'PRIVATE_IMPORT_SOURCE_SENTINEL',
    'originals/',
    'public_published_source',
] as $forbidden) {
    if (str_contains($raw, $forbidden)) {
        fwrite(STDERR, "VideoObject leaked private field: ".$forbidden."\n");
        exit(1);
    }
}

echo "OK truthful privacy-safe VideoObject\n";
PHP
}

fetch_media_sitemap_for_video() {
    curl --fail --silent --show-error \
        --header "Host: $HOSTILE_HOST" \
        "$BASE_URL/sitemap.xml" \
        -o /tmp/public-video-sitemap-index.xml

    local path
    while IFS= read -r path; do
        [ -n "$path" ] || continue

        curl --fail --silent --show-error \
            --header "Host: $HOSTILE_HOST" \
            "$BASE_URL$path" \
            -o /tmp/public-video-media-sitemap.xml

        if grep -F "<loc>$CANONICAL_URL</loc>" /tmp/public-video-media-sitemap.xml >/dev/null; then
            printf '%s' "$path" >/tmp/public-video-media-sitemap-path
            return 0
        fi
    done < <(
        grep -oE '<loc>[^<]*/sitemaps/media-[1-9][0-9]*\.xml</loc>' /tmp/public-video-sitemap-index.xml \
            | sed -E 's#.*(/sitemaps/media-[1-9][0-9]*\.xml).*</loc>#\1#' \
            || true
    )

    return 1
}

assert_video_sitemap_entry() {
    fetch_media_sitemap_for_video

    expect_contains /tmp/public-video-media-sitemap.xml \
        'xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"' \
        "Video sitemap namespace"
    expect_contains /tmp/public-video-media-sitemap.xml \
        "<video:thumbnail_loc>$POSTER_URL</video:thumbnail_loc>" \
        "Video sitemap poster"
    expect_contains /tmp/public-video-media-sitemap.xml \
        '<video:title>Public Video Fixture</video:title>' \
        "Video sitemap title"
    expect_contains /tmp/public-video-media-sitemap.xml \
        '<video:description>Public video playback integration fixture.</video:description>' \
        "Video sitemap description"
    expect_contains /tmp/public-video-media-sitemap.xml \
        "<video:content_loc>$PLAYBACK_URL</video:content_loc>" \
        "Video sitemap generated browser MP4"
    expect_contains /tmp/public-video-media-sitemap.xml \
        '<video:duration>1</video:duration>' \
        "Video sitemap duration"
    expect_contains /tmp/public-video-media-sitemap.xml \
        "<video:publication_date>$PUBLICATION_DATE</video:publication_date>" \
        "Video sitemap truthful publication date"
    expect_absent /tmp/public-video-media-sitemap.xml "$HOSTILE_HOST" \
        "Host header cannot influence video sitemap URLs"
    expect_absent /tmp/public-video-media-sitemap.xml 'PRIVATE-video-source-sentinel.mov' \
        "Video sitemap does not expose original filename"
    expect_absent /tmp/public-video-media-sitemap.xml 'PRIVATE_RAW_METADATA_SENTINEL' \
        "Video sitemap does not expose raw metadata"
    expect_absent /tmp/public-video-media-sitemap.xml 'PRIVATE LOCATION SENTINEL' \
        "Video sitemap does not expose private location"
    expect_absent /tmp/public-video-media-sitemap.xml 'PRIVATE_IMPORT_SOURCE_SENTINEL' \
        "Video sitemap does not expose publication provenance source"
}

assert_video_metadata_absent_from_own_sitemap_entry() {
    fetch_media_sitemap_for_video

    PUBLIC_VIDEO_SITEMAP_FILE="/tmp/public-video-media-sitemap.xml" \
    PUBLIC_VIDEO_CANONICAL="$CANONICAL_URL" \
    php <<'PHP'
<?php
$xml = (string) file_get_contents((string) getenv('PUBLIC_VIDEO_SITEMAP_FILE'));
$canonical = preg_quote((string) getenv('PUBLIC_VIDEO_CANONICAL'), '~');

if (!preg_match('~<url>\\s*<loc>'.$canonical.'</loc>(.*?)</url>~s', $xml, $match)) {
    fwrite(STDERR, "Canonical MediaAsset sitemap entry missing.\n");
    exit(1);
}

if (str_contains((string) $match[1], '<video:video>')) {
    fwrite(STDERR, "Video discovery metadata should be absent from this canonical entry.\n");
    exit(1);
}

echo "OK canonical MediaAsset remains listed without fabricated video discovery metadata\n";
PHP
}

assert_media_canonical_absent_from_sitemaps() {
    curl --fail --silent --show-error \
        --header "Host: $HOSTILE_HOST" \
        "$BASE_URL/sitemap.xml" \
        -o /tmp/public-video-sitemap-index.xml

    local path
    while IFS= read -r path; do
        [ -n "$path" ] || continue

        curl --fail --silent --show-error \
            --header "Host: $HOSTILE_HOST" \
            "$BASE_URL$path" \
            -o /tmp/public-video-absence-sitemap.xml

        if grep -F "<loc>$CANONICAL_URL</loc>" /tmp/public-video-absence-sitemap.xml >/dev/null; then
            echo "FAIL excluded video MediaAsset remained in sitemap: $path"
            cat /tmp/public-video-absence-sitemap.xml
            exit 1
        fi
    done < <(
        grep -oE '<loc>[^<]*/sitemaps/media-[1-9][0-9]*\.xml</loc>' /tmp/public-video-sitemap-index.xml \
            | sed -E 's#.*(/sitemaps/media-[1-9][0-9]*\.xml).*</loc>#\1#' \
            || true
    )

    echo "OK excluded video MediaAsset absent from all media sitemap chunks"
}

expect_header() {
    local file="$1"
    local value="$2"
    local label="$3"

    if ! grep -i -F "$value" "$file" >/dev/null; then
        echo "FAIL $label"
        cat "$file"
        exit 1
    fi

    echo "OK $label"
}

expect_no_header() {
    local file="$1"
    local value="$2"
    local label="$3"

    if grep -i -F "$value" "$file" >/dev/null; then
        echo "FAIL $label"
        cat "$file"
        exit 1
    fi

    echo "OK $label"
}

fetch_page "/media/$MEDIA_ID" /tmp/public-video-page.html /tmp/public-video-page.headers
expect_contains /tmp/public-video-page.html '<title>Public Video Fixture · Mediarama</title>' "Video detail title"
expect_contains /tmp/public-video-page.html "$CANONICAL_URL" "Video detail canonical uses trusted origin"
expect_contains /tmp/public-video-page.html "$POSTER_URL" "Video Open Graph poster uses trusted origin"
expect_marker_url /tmp/public-video-page.html 'poster="' "/media/$MEDIA_ID/video/v1/poster" "Video player uses public poster derivative"
expect_marker_url /tmp/public-video-page.html 'src="' "/media/$MEDIA_ID/video/v1/browser_mp4" "Video player uses public browser rendition"
expect_contains /tmp/public-video-page.html '<video' "Video detail renders a browser video element"
expect_contains /tmp/public-video-page.html 'controls' "Video player exposes user controls"
expect_contains /tmp/public-video-page.html 'preload="metadata"' "Video player avoids eager full download"
expect_absent /tmp/public-video-page.html 'autoplay' "Video player does not autoplay"
expect_absent /tmp/public-video-page.html 'PRIVATE-video-source-sentinel.mov' "Original filename remains private"
expect_absent /tmp/public-video-page.html 'PRIVATE_RAW_METADATA_SENTINEL' "Raw metadata remains private"
expect_absent /tmp/public-video-page.html 'PRIVATE LOCATION SENTINEL' "Private canonical location remains private"
expect_contains /tmp/public-video-page.html 'application/ld+json' "Published indexable video emits VideoObject"
assert_video_object /tmp/public-video-page.html
assert_video_sitemap_entry
expect_absent /tmp/public-video-page.html "$HOSTILE_HOST" "Host header cannot influence video page metadata"

expect_status 200 "/media/$MEDIA_ID/video/v1/poster" /tmp/public-video-poster.jpg /tmp/public-video-poster.headers
expect_header /tmp/public-video-poster.headers 'Content-Type: image/jpeg' "Poster uses image/jpeg"
test -s /tmp/public-video-poster.jpg

expect_status 200 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-full.mp4 /tmp/public-video-full.headers
expect_header /tmp/public-video-full.headers 'Content-Type: video/mp4' "Playback rendition uses video/mp4"
expect_header /tmp/public-video-full.headers 'Accept-Ranges: bytes' "Playback rendition advertises byte ranges"
test "$(wc -c < /tmp/public-video-full.mp4 | tr -d ' ')" = "$PLAYBACK_SIZE"

expect_status 206 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-range.bin /tmp/public-video-range.headers -H 'Range: bytes=0-99'
expect_header /tmp/public-video-range.headers "Content-Range: bytes 0-99/$PLAYBACK_SIZE" "Playback supports explicit byte ranges"
expect_header /tmp/public-video-range.headers 'Content-Length: 100' "Partial playback response has exact content length"
test "$(wc -c < /tmp/public-video-range.bin | tr -d ' ')" = "100"

expect_status 206 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-suffix.bin /tmp/public-video-suffix.headers -H 'Range: bytes=-64'
expect_header /tmp/public-video-suffix.headers 'Content-Length: 64' "Playback supports suffix byte ranges"
test "$(wc -c < /tmp/public-video-suffix.bin | tr -d ' ')" = "64"

expect_status 416 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-invalid-range.bin /tmp/public-video-invalid-range.headers -H 'Range: bytes=999999999-'
expect_header /tmp/public-video-invalid-range.headers "Content-Range: bytes */$PLAYBACK_SIZE" "Unsatisfiable byte range reports total resource size"

expect_status 404 "/media/$MEDIA_ID/original" /tmp/public-video-original.html /tmp/public-video-original.headers

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets
     SET public_published_at = NULL,
         public_updated_at = NULL,
         public_published_origin = NULL,
         public_published_source = NULL
     WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID')],
);
$db->close();
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-video-unknown-publication.html /tmp/public-video-unknown-publication.headers
expect_absent /tmp/public-video-unknown-publication.html 'application/ld+json' "Unknown public publication date suppresses VideoObject"
assert_video_metadata_absent_from_own_sitemap_entry

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" PUBLIC_VIDEO_PUBLISHED_AT="$PUBLICATION_DATE" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets
     SET public_published_at = :published,
         public_updated_at = :published,
         public_published_origin = 'imported',
         public_published_source = 'PRIVATE_IMPORT_SOURCE_SENTINEL'
     WHERE id = :id",
    [
        'published' => (string) getenv('PUBLIC_VIDEO_PUBLISHED_AT'),
        'id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID'),
    ],
);
$db->close();
PHP

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET search_index_policy = 'noindex' WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID')],
);
$db->close();
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-video-noindex.html /tmp/public-video-noindex.headers
expect_header /tmp/public-video-noindex.headers 'X-Robots-Tag: noindex' "Video page respects MediaAsset noindex"
expect_contains /tmp/public-video-noindex.html "$POSTER_URL" "Noindex video retains social poster"
expect_absent /tmp/public-video-noindex.html 'application/ld+json' "Noindex video emits no structured discovery object"

expect_status 206 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-noindex-range.bin /tmp/public-video-noindex-range.headers -H 'Range: bytes=0-31'
expect_header /tmp/public-video-noindex-range.headers 'X-Robots-Tag: noindex' "Video rendition respects MediaAsset noindex"
assert_media_canonical_absent_from_sitemaps

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" PUBLIC_VIDEO_COLLECTION_ID="$COLLECTION_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET search_index_policy = 'inherit' WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID')],
);
$db->executeStatement(
    "UPDATE collections SET search_index_policy = 'noindex' WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_COLLECTION_ID')],
);
$db->close();
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-video-collection-noindex.html /tmp/public-video-collection-noindex.headers
expect_no_header /tmp/public-video-collection-noindex.headers 'X-Robots-Tag: noindex' "Collection noindex does not override video MediaAsset policy"
expect_contains /tmp/public-video-collection-noindex.html 'application/ld+json' "Collection noindex leaves VideoObject discoverable"
assert_video_sitemap_entry

PUBLIC_VIDEO_COLLECTION_ID="$COLLECTION_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE collections SET search_index_policy = 'inherit', password_protected = TRUE WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_COLLECTION_ID')],
);
$db->close();
PHP

expect_status 404 "/media/$MEDIA_ID" /tmp/public-video-private.html /tmp/public-video-private.headers
expect_status 404 "/media/$MEDIA_ID/video/v1/poster" /tmp/public-video-private-poster.html /tmp/public-video-private-poster.headers
expect_status 404 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-private-playback.html /tmp/public-video-private-playback.headers
assert_media_canonical_absent_from_sitemaps
echo "OK Private-only video MediaAsset is absent from sitemap discovery"

PUBLIC_VIDEO_COLLECTION_ID="$COLLECTION_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE collections SET password_protected = FALSE WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_COLLECTION_ID')],
);
$db->close();
PHP

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET moderation_state = 'pending_review' WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID')],
);
$db->close();
PHP

expect_status 404 "/media/$MEDIA_ID" /tmp/public-video-pending.html /tmp/public-video-pending.headers
assert_media_canonical_absent_from_sitemaps
echo "OK Pending-review video is absent from page and sitemap discovery"

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets
     SET moderation_state = 'published',
         processing_state = 'failed'
     WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID')],
);
$db->close();
PHP

expect_status 404 "/media/$MEDIA_ID" /tmp/public-video-not-ready.html /tmp/public-video-not-ready.headers
assert_media_canonical_absent_from_sitemaps
echo "OK Non-ready video is absent from page and sitemap discovery"

PUBLIC_VIDEO_MEDIA_ID="$MEDIA_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET processing_state = 'ready' WHERE id = :id",
    ['id' => (string) getenv('PUBLIC_VIDEO_MEDIA_ID')],
);
$db->close();
PHP

php bin/console mediarama:platform:publication-settings --public-publishing=off >/tmp/public-video-publishing-off.txt
expect_status 404 "/media/$MEDIA_ID" /tmp/public-video-site-private.html /tmp/public-video-site-private.headers
expect_status 404 "/media/$MEDIA_ID/video/v1/browser_mp4" /tmp/public-video-site-private-playback.html /tmp/public-video-site-private-playback.headers
php bin/console mediarama:platform:publication-settings --public-publishing=on >/tmp/public-video-publishing-on.txt

echo "Public video playback, privacy and byte-range integration checks passed."
