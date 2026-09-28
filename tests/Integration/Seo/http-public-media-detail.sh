#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${PUBLIC_BASE_URL:?PUBLIC_BASE_URL must be set}"

BASE_URL="http://127.0.0.1:8089"
CANONICAL_ORIGIN="${PUBLIC_BASE_URL%/}"
HOSTILE_HOST="media-attacker.example"
SERVER_PID=""

php bin/console mediarama:platform:deployment-profile public_publishing >/tmp/public-media-profile.txt

php <<'PHP'
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

$db->executeStatement(
    "UPDATE collections
     SET visibility = 'public',
         password_protected = FALSE,
         password_reset_required = FALSE,
         search_index_policy = 'inherit'
     WHERE title IN ('Fixture Category', 'Fixture Album')"
);
$db->executeStatement(
    "UPDATE media_assets
     SET title = 'Fixture Photo',
         description = 'Imported fixture caption',
         moderation_state = 'published',
         processing_state = 'ready',
         search_index_policy = 'inherit'
     WHERE original_filename = 'sample.jpg'"
);

$mediaId = (string) $db->fetchOne(
    "SELECT id FROM media_assets WHERE original_filename = 'sample.jpg'"
);
$collectionId = (string) $db->fetchOne(
    "SELECT id FROM collections WHERE title = 'Fixture Album'"
);
$ownerId = (string) $db->fetchOne(
    "SELECT id FROM users WHERE username = 'fixture-user'"
);

if ($mediaId === '' || $collectionId === '' || $ownerId === '') {
    throw new RuntimeException('Missing public MediaAsset integration fixture.');
}

$derivative = $db->fetchAssociative(
    <<<'SQL'
SELECT profile, processing_version
FROM media_derivatives
WHERE media_id = :media
  AND kind = 'image'
  AND profile IN ('large', 'preview', 'thumbnail')
ORDER BY
    CASE profile
        WHEN 'large' THEN 1
        WHEN 'preview' THEN 2
        WHEN 'thumbnail' THEN 3
        ELSE 4
    END,
    processing_version DESC
LIMIT 1
SQL,
    ['media' => $mediaId],
);

if ($derivative === false) {
    throw new RuntimeException('Missing public image derivative for detail-page test.');
}

$privateCollectionId = Uuid::v7();
$now = (new DateTimeImmutable())->format(DATE_ATOM);
$db->insert(
    'collections',
    [
        'id' => $privateCollectionId->toRfc4122(),
        'owner_id' => $ownerId,
        'parent_id' => null,
        'cover_media_id' => null,
        'slug' => null,
        'title' => 'PRIVATE MEMBERSHIP SENTINEL',
        'description' => 'PRIVATE DESCRIPTION SENTINEL',
        'visibility' => 'private',
        'position' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
        'password_protected' => false,
        'password_hash' => null,
        'password_hint' => null,
        'password_reset_required' => false,
        'search_index_policy' => 'noindex',
    ],
    [
        'password_protected' => ParameterType::BOOLEAN,
        'password_reset_required' => ParameterType::BOOLEAN,
    ],
);
$db->insert('collection_media', [
    'collection_id' => $privateCollectionId->toRfc4122(),
    'media_id' => $mediaId,
    'position' => 0,
    'added_by' => null,
    'created_at' => $now,
]);

foreach ([
    'media' => $mediaId,
    'collection' => $collectionId,
    'profile' => (string) $derivative['profile'],
    'version' => (string) $derivative['processing_version'],
    'private_collection' => $privateCollectionId->toRfc4122(),
] as $name => $value) {
    file_put_contents('/tmp/public-media-'.$name, $value);
}

$db->close();
PHP

MEDIA_ID="$(cat /tmp/public-media-media)"
COLLECTION_ID="$(cat /tmp/public-media-collection)"
PROFILE="$(cat /tmp/public-media-profile)"
VERSION="$(cat /tmp/public-media-version)"
PRIVATE_COLLECTION_ID="$(cat /tmp/public-media-private_collection)"
CANONICAL_URL="$CANONICAL_ORIGIN/media/$MEDIA_ID"
IMAGE_URL="$CANONICAL_ORIGIN/media/$MEDIA_ID/derivatives/v$VERSION/$PROFILE"

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    PUBLIC_MEDIA_PRIVATE_COLLECTION_ID="$PRIVATE_COLLECTION_ID" php <<'PHP' >/dev/null 2>&1 || true
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE collections
     SET password_protected = FALSE,
         password_reset_required = FALSE,
         search_index_policy = 'inherit'
     WHERE title IN ('Fixture Category', 'Fixture Album')"
);
$db->executeStatement(
    "UPDATE media_assets
     SET title = 'Fixture Photo',
         description = 'Imported fixture caption',
         moderation_state = 'published',
         processing_state = 'ready',
         search_index_policy = 'inherit'
     WHERE original_filename = 'sample.jpg'"
);
$privateCollectionId = (string) getenv('PUBLIC_MEDIA_PRIVATE_COLLECTION_ID');
if ($privateCollectionId !== '') {
    $db->executeStatement(
        'DELETE FROM collections WHERE id = :id',
        ['id' => $privateCollectionId],
    );
}
$db->close();
PHP

    php bin/console mediarama:platform:deployment-profile private_workspace >/dev/null 2>&1 || true
}
trap cleanup EXIT

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8089 -t public public/index.php >/tmp/mediarama-public-media-http.log 2>&1 &
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

    if ! curl --fail --silent --show-error \
        --header "Host: $HOSTILE_HOST" \
        --dump-header "$headers" \
        "$BASE_URL$path" \
        -o "$body"; then
        cat /tmp/mediarama-public-media-http.log || true
        exit 1
    fi
}

expect_status() {
    local expected="$1"
    local path="$2"
    local body="$3"
    local status

    status="$(curl --silent --show-error \
        --header "Host: $HOSTILE_HOST" \
        --output "$body" \
        --write-out '%{http_code}' \
        "$BASE_URL$path")"

    if [ "$status" != "$expected" ]; then
        echo "FAIL expected HTTP $expected but got $status for $path"
        cat /tmp/mediarama-public-media-http.log || true
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

expect_tag_url() {
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

cat >/tmp/assert-public-media-json.php <<'PHP'
<?php

$html = (string) file_get_contents($argv[1]);
$canonical = $argv[2];
$imageUrl = $argv[3];
$expectedTitle = $argv[4];
$expectedDescription = $argv[5];

if (!preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match)) {
    fwrite(STDERR, "ImageObject JSON-LD script missing.\n");
    exit(1);
}

$data = json_decode(trim($match[1]), true, flags: JSON_THROW_ON_ERROR);

foreach ([
    '@context' => 'https://schema.org',
    '@type' => 'ImageObject',
    'contentUrl' => $imageUrl,
    'name' => $expectedTitle,
    'caption' => $expectedDescription,
    'mainEntityOfPage' => $canonical,
] as $key => $expected) {
    if (($data[$key] ?? null) !== $expected) {
        fwrite(STDERR, "Unexpected ImageObject field ".$key.".\n");
        exit(1);
    }
}

foreach ([
    'sample.jpg',
    'camera_make',
    'camera_model',
    'latitude',
    'longitude',
    'location_name',
    'storage_key',
    'metadata_provenance',
    'PRIVATE MEMBERSHIP SENTINEL',
    'PRIVATE DESCRIPTION SENTINEL',
] as $forbidden) {
    if (str_contains((string) $match[1], $forbidden)) {
        fwrite(STDERR, "Structured media metadata leaked forbidden value: ".$forbidden."\n");
        exit(1);
    }
}

echo "OK public ImageObject structured-data boundary\n";
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-media.html /tmp/public-media.headers
expect_contains /tmp/public-media.html '<title>Fixture Photo · Mediarama</title>' "Media detail title"
expect_contains /tmp/public-media.html '<meta name="description" content="Imported fixture caption">' "Media detail description"
expect_tag_url /tmp/public-media.html 'rel="canonical"' "$CANONICAL_URL" "Media detail canonical"
expect_tag_url /tmp/public-media.html 'property="og:url"' "$CANONICAL_URL" "Media detail Open Graph URL"
expect_tag_url /tmp/public-media.html 'property="og:image"' "$IMAGE_URL" "Media detail Open Graph image"
expect_tag_url /tmp/public-media.html 'src="' "/media/$MEDIA_ID/derivatives/v$VERSION/$PROFILE" "Media detail renders public derivative"
expect_absent /tmp/public-media.html "$HOSTILE_HOST" "Host header cannot influence MediaAsset metadata"
expect_absent /tmp/public-media.html 'sample.jpg' "Source filename is not public"
expect_absent /tmp/public-media.html 'PRIVATE MEMBERSHIP SENTINEL' "Private membership title is not public"
expect_absent /tmp/public-media.html 'PRIVATE DESCRIPTION SENTINEL' "Private membership description is not public"
expect_no_header /tmp/public-media.headers 'X-Robots-Tag: noindex' "Indexable MediaAsset does not emit noindex"
php /tmp/assert-public-media-json.php \
    /tmp/public-media.html \
    "$CANONICAL_URL" \
    "$IMAGE_URL" \
    "Fixture Photo" \
    "Imported fixture caption"

fetch_page "/collections/$COLLECTION_ID" /tmp/public-media-collection.html /tmp/public-media-collection.headers
expect_contains /tmp/public-media-collection.html "href=\"/media/$MEDIA_ID\"" "Public Collection links stable MediaAsset identity"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET title = NULL, description = NULL WHERE original_filename = 'sample.jpg'"
);
$db->close();
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-media-fallback.html /tmp/public-media-fallback.headers
expect_contains /tmp/public-media-fallback.html '<title>Image · Mediarama</title>' "Untitled image uses deterministic title fallback"
expect_contains /tmp/public-media-fallback.html '<meta name="description" content="View this image on Mediarama.">' "Image uses deterministic description fallback"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets
     SET title = 'Fixture Photo',
         description = 'Imported fixture caption',
         search_index_policy = 'noindex'
     WHERE original_filename = 'sample.jpg'"
);
$db->close();
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-media-noindex.html /tmp/public-media-noindex.headers
expect_header /tmp/public-media-noindex.headers 'X-Robots-Tag: noindex' "MediaAsset noindex keeps robots boundary"
expect_tag_url /tmp/public-media-noindex.html 'rel="canonical"' "$CANONICAL_URL" "Noindex MediaAsset keeps canonical"
expect_tag_url /tmp/public-media-noindex.html 'property="og:image"' "$IMAGE_URL" "Noindex MediaAsset keeps social image"
expect_absent /tmp/public-media-noindex.html 'application/ld+json' "Noindex MediaAsset omits index-oriented structured data"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET search_index_policy = 'inherit' WHERE original_filename = 'sample.jpg'"
);
$db->executeStatement(
    "UPDATE collections SET search_index_policy = 'noindex' WHERE title = 'Fixture Album'"
);
$db->close();
PHP

fetch_page "/media/$MEDIA_ID" /tmp/public-media-collection-noindex.html /tmp/public-media-collection-noindex.headers
expect_no_header /tmp/public-media-collection-noindex.headers 'X-Robots-Tag: noindex' "Collection noindex does not implicitly change MediaAsset policy"
php /tmp/assert-public-media-json.php \
    /tmp/public-media-collection-noindex.html \
    "$CANONICAL_URL" \
    "$IMAGE_URL" \
    "Fixture Photo" \
    "Imported fixture caption"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE collections SET search_index_policy = 'inherit' WHERE title = 'Fixture Album'"
);
$db->executeStatement(
    "UPDATE collections SET password_protected = TRUE WHERE title = 'Fixture Category'"
);
$db->close();
PHP

expect_status 404 "/media/$MEDIA_ID" /tmp/public-media-private-parent.html
expect_absent /tmp/public-media-private-parent.html 'Fixture Photo' "Inaccessible ancestry does not expose MediaAsset metadata"
expect_absent /tmp/public-media-private-parent.html "$CANONICAL_URL" "Inaccessible ancestry does not expose MediaAsset canonical"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE collections SET password_protected = FALSE WHERE title = 'Fixture Category'"
);
$db->executeStatement(
    "UPDATE media_assets SET moderation_state = 'pending_review' WHERE original_filename = 'sample.jpg'"
);
$db->close();
PHP

expect_status 404 "/media/$MEDIA_ID" /tmp/public-media-unpublished.html

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets
     SET moderation_state = 'published',
         processing_state = 'failed'
     WHERE original_filename = 'sample.jpg'"
);
$db->close();
PHP

expect_status 404 "/media/$MEDIA_ID" /tmp/public-media-failed.html

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET processing_state = 'ready' WHERE original_filename = 'sample.jpg'"
);
$db->close();
PHP

php bin/console mediarama:platform:publication-settings --public-publishing=off >/tmp/public-media-publishing-off.txt
expect_status 404 "/media/$MEDIA_ID" /tmp/public-media-site-private.html
php bin/console mediarama:platform:publication-settings --public-publishing=on >/tmp/public-media-publishing-on.txt

fetch_page "/media/$MEDIA_ID" /tmp/public-media-final.html /tmp/public-media-final.headers
expect_no_header /tmp/public-media-final.headers 'X-Robots-Tag: noindex' "MediaAsset detail recovers after safety-gate tests"

echo "Public MediaAsset identity/detail boundary checks passed."
