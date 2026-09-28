#!/usr/bin/env bash
set -euo pipefail

php bin/console mediarama:platform:deployment-profile public_publishing >/tmp/publication-profile.txt

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
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
     SET moderation_state = 'published',
         search_index_policy = 'inherit'
     WHERE title = 'Fixture Photo'"
);

$categoryId = (string) $db->fetchOne(
    "SELECT id FROM collections WHERE title = 'Fixture Category'"
);
$collectionId = (string) $db->fetchOne(
    "SELECT id FROM collections WHERE title = 'Fixture Album'"
);
$mediaId = (string) $db->fetchOne(
    "SELECT id FROM media_assets WHERE title = 'Fixture Photo'"
);
$version = (string) $db->fetchOne(
    "SELECT MAX(processing_version)
     FROM media_derivatives
     WHERE media_id = :media
       AND kind = 'image'
       AND profile = 'thumbnail'",
    ['media' => $mediaId],
);

foreach ([
    'category' => $categoryId,
    'collection' => $collectionId,
    'media' => $mediaId,
    'version' => $version,
] as $name => $value) {
    if ($value === '') {
        throw new RuntimeException('Missing public fixture value: '.$name);
    }

    file_put_contents('/tmp/policy-'.$name, $value);
}

$db->executeStatement(
    "INSERT INTO collection_media (
        collection_id, media_id, position, added_by, created_at
     ) VALUES (
        :collection, :media, 999, NULL, CURRENT_TIMESTAMP
     )
     ON CONFLICT (collection_id, media_id) DO NOTHING",
    [
        'collection' => $categoryId,
        'media' => $mediaId,
    ],
);

$db->close();
PHP

CATEGORY_ID="$(cat /tmp/policy-category)"
COLLECTION_ID="$(cat /tmp/policy-collection)"
MEDIA_ID="$(cat /tmp/policy-media)"
VERSION="$(cat /tmp/policy-version)"

php -S 127.0.0.1:8080 -t public public/index.php >/tmp/mediarama-policy-http.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" || true' EXIT
sleep 1

expect_status() {
  local expected="$1"
  local url="$2"
  local output="${3:-/tmp/policy-body}"
  local status
  status="$(curl --silent --show-error --output "$output" --write-out '%{http_code}' "$url")"

  if [ "$status" != "$expected" ]; then
    echo "Expected HTTP $expected but got $status for $url"
    cat /tmp/mediarama-policy-http.log || true
    cat "$output" || true
    exit 1
  fi
}

fetch_headers() {
  local url="$1"
  local headers="$2"
  local body="$3"

  if ! curl --fail --silent --show-error -D "$headers" "$url" -o "$body"; then
    cat /tmp/mediarama-policy-http.log || true
    exit 1
  fi
}

expect_noindex() {
  local headers="$1"

  if ! grep -i -E '^x-robots-tag:.*noindex' "$headers" >/dev/null; then
    echo "Expected noindex header in $headers"
    cat "$headers"
    exit 1
  fi
}

expect_indexable() {
  local headers="$1"

  if grep -i -E '^x-robots-tag:.*noindex' "$headers" >/dev/null; then
    echo "Unexpected noindex header in $headers"
    cat "$headers"
    exit 1
  fi
}

count_search_items() {
  local url="$1"
  local output="$2"

  curl --fail --silent --show-error "$url" -o "$output"
  php -r '
    $decoded = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    echo count($decoded["items"] ?? []);
  ' "$output"
}

fetch_headers "http://127.0.0.1:8080/collections" /tmp/policy-root.headers /tmp/policy-root.html
expect_indexable /tmp/policy-root.headers

fetch_headers "http://127.0.0.1:8080/collections/$COLLECTION_ID" /tmp/policy-collection.headers /tmp/policy-collection.html
expect_indexable /tmp/policy-collection.headers

fetch_headers "http://127.0.0.1:8080/media/$MEDIA_ID/derivatives/v$VERSION/thumbnail" /tmp/policy-media.headers /tmp/policy-media.webp
expect_indexable /tmp/policy-media.headers

php bin/console mediarama:search-index:set collection "$CATEGORY_ID" noindex
php bin/console mediarama:search-index:set collection "$COLLECTION_ID" index
php bin/console mediarama:search-index:set media "$MEDIA_ID" inherit

fetch_headers "http://127.0.0.1:8080/collections/$CATEGORY_ID" /tmp/policy-category-noindex.headers /tmp/policy-category-noindex.html
expect_noindex /tmp/policy-category-noindex.headers

fetch_headers "http://127.0.0.1:8080/collections/$COLLECTION_ID" /tmp/policy-album-index.headers /tmp/policy-album-index.html
expect_indexable /tmp/policy-album-index.headers

fetch_headers "http://127.0.0.1:8080/media/$MEDIA_ID/derivatives/v$VERSION/thumbnail" /tmp/policy-media-independent.headers /tmp/policy-media-independent.webp
expect_indexable /tmp/policy-media-independent.headers

php bin/console mediarama:search-index:set media "$MEDIA_ID" noindex
fetch_headers "http://127.0.0.1:8080/media/$MEDIA_ID/derivatives/v$VERSION/thumbnail" /tmp/policy-media-noindex.headers /tmp/policy-media-noindex.webp
expect_noindex /tmp/policy-media-noindex.headers

php bin/console mediarama:platform:publication-settings --public-publishing=on --search-index-default=noindex
php bin/console mediarama:search-index:set collection "$CATEGORY_ID" inherit
php bin/console mediarama:search-index:set collection "$COLLECTION_ID" inherit
php bin/console mediarama:search-index:set media "$MEDIA_ID" inherit

fetch_headers "http://127.0.0.1:8080/collections" /tmp/policy-site-noindex.headers /tmp/policy-site-noindex.html
expect_noindex /tmp/policy-site-noindex.headers

fetch_headers "http://127.0.0.1:8080/collections/$COLLECTION_ID" /tmp/policy-inherit-collection.headers /tmp/policy-inherit-collection.html
expect_noindex /tmp/policy-inherit-collection.headers

fetch_headers "http://127.0.0.1:8080/media/$MEDIA_ID/derivatives/v$VERSION/thumbnail" /tmp/policy-inherit-media.headers /tmp/policy-inherit-media.webp
expect_noindex /tmp/policy-inherit-media.headers

php bin/console mediarama:search-index:set collection "$COLLECTION_ID" index
php bin/console mediarama:search-index:set media "$MEDIA_ID" index

fetch_headers "http://127.0.0.1:8080/collections/$COLLECTION_ID" /tmp/policy-explicit-collection.headers /tmp/policy-explicit-collection.html
expect_indexable /tmp/policy-explicit-collection.headers

fetch_headers "http://127.0.0.1:8080/media/$MEDIA_ID/derivatives/v$VERSION/thumbnail" /tmp/policy-explicit-media.headers /tmp/policy-explicit-media.webp
expect_indexable /tmp/policy-explicit-media.headers

php bin/console mediarama:platform:publication-settings --public-publishing=off

expect_status 404 "http://127.0.0.1:8080/collections"
expect_status 404 "http://127.0.0.1:8080/collections/$COLLECTION_ID"
expect_status 404 "http://127.0.0.1:8080/media/$MEDIA_ID/derivatives/v$VERSION/thumbnail"

SEARCH_COUNT="$(count_search_items "http://127.0.0.1:8080/api/media?q=Fixture" /tmp/policy-disabled-search.json)"
if [ "$SEARCH_COUNT" != "0" ]; then
  echo "Public search returned $SEARCH_COUNT item(s) while public publishing was disabled."
  exit 1
fi

php bin/console mediarama:platform:deployment-profile public_publishing
php bin/console mediarama:search-index:set collection "$CATEGORY_ID" inherit
php bin/console mediarama:search-index:set collection "$COLLECTION_ID" inherit
php bin/console mediarama:search-index:set media "$MEDIA_ID" inherit

echo "Publication/index HTTP policy checks passed."
