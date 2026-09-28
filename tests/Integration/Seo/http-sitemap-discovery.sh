#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${PUBLIC_BASE_URL:?PUBLIC_BASE_URL must be set}"

BASE_URL="http://127.0.0.1:8080"
PRIVATE_SENTINEL="sitemap-private-metadata-must-never-leak"

reset_fixture() {
  php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL'))
);

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
         search_index_policy = 'inherit',
         metadata = metadata - 'sitemap_private_probe'
     WHERE title = 'Fixture Photo'"
);
$db->close();
PHP
  php bin/console mediarama:platform:deployment-profile public_publishing >/dev/null
}

reset_fixture

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL'))
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

if ($categoryId === '' || $collectionId === '' || $mediaId === '') {
    throw new RuntimeException('Sitemap fixture identities are missing.');
}

$db->executeStatement(
    "INSERT INTO collection_media (
        collection_id, media_id, position, added_by, created_at
     ) VALUES (
        :collection, :media, 999, NULL, CURRENT_TIMESTAMP
     )
     ON CONFLICT (collection_id, media_id) DO NOTHING",
    ['collection' => $categoryId, 'media' => $mediaId],
);

$db->executeStatement(
    "UPDATE media_assets
     SET metadata = jsonb_set(
         metadata,
         '{sitemap_private_probe}',
         to_jsonb(CAST(:sentinel AS text)),
         TRUE
     )
     WHERE id = :media",
    [
        'sentinel' => 'sitemap-private-metadata-must-never-leak',
        'media' => $mediaId,
    ],
);

$derivative = $db->fetchAssociative(
    "SELECT profile, processing_version
     FROM media_derivatives
     WHERE media_id = :media
       AND kind = 'image'
       AND profile IN ('preview', 'thumbnail')
     ORDER BY
         CASE profile WHEN 'preview' THEN 1 WHEN 'thumbnail' THEN 2 ELSE 3 END,
         processing_version DESC
     LIMIT 1",
    ['media' => $mediaId],
);
if ($derivative === false) {
    throw new RuntimeException('Sitemap image derivative is missing.');
}

file_put_contents('/tmp/sitemap-category-id', $categoryId);
file_put_contents('/tmp/sitemap-collection-id', $collectionId);
file_put_contents('/tmp/sitemap-media-id', $mediaId);
file_put_contents('/tmp/sitemap-media-profile', (string) $derivative['profile']);
file_put_contents('/tmp/sitemap-media-version', (string) $derivative['processing_version']);
$db->close();
PHP

CATEGORY_ID="$(cat /tmp/sitemap-category-id)"
COLLECTION_ID="$(cat /tmp/sitemap-collection-id)"
MEDIA_ID="$(cat /tmp/sitemap-media-id)"
MEDIA_PROFILE="$(cat /tmp/sitemap-media-profile)"
MEDIA_VERSION="$(cat /tmp/sitemap-media-version)"

php -S 127.0.0.1:8080 -t public public/index.php >/tmp/mediarama-sitemap-http.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; reset_fixture' EXIT

for _ in $(seq 1 50); do
  if curl --fail --silent "$BASE_URL/sitemap.xml" >/dev/null 2>&1; then
    break
  fi
  sleep 0.2
done

expect_status() {
  local expected="$1"
  local url="$2"
  local output="${3:-/tmp/sitemap-status-body}"
  local status

  status="$(curl --silent --show-error --output "$output" --write-out '%{http_code}' "$url")"
  if [ "$status" != "$expected" ]; then
    echo "FAIL expected HTTP $expected but got $status for $url"
    cat /tmp/mediarama-sitemap-http.log || true
    cat "$output" || true
    exit 1
  fi
}

fetch_xml() {
  local url="$1"
  local headers="$2"
  local body="$3"
  shift 3

  if ! curl --fail --silent --show-error "$@" -D "$headers" "$url" -o "$body"; then
    cat /tmp/mediarama-sitemap-http.log || true
    exit 1
  fi

  grep -i -E '^content-type: application/xml;.*charset=UTF-8' "$headers" >/dev/null
  grep -F '<?xml version="1.0" encoding="UTF-8"?>' "$body" >/dev/null
}

assert_contains() {
  local needle="$1"
  local file="$2"
  local label="$3"

  if ! grep -F "$needle" "$file" >/dev/null; then
    echo "FAIL missing $label: $needle"
    cat "$file"
    exit 1
  fi
}

assert_absent() {
  local needle="$1"
  local file="$2"
  local label="$3"

  if grep -F "$needle" "$file" >/dev/null; then
    echo "FAIL leaked/unexpected $label: $needle"
    cat "$file"
    exit 1
  fi
}

assert_count() {
  local expected="$1"
  local needle="$2"
  local file="$3"
  local label="$4"
  local actual

  actual="$(grep -F -c "$needle" "$file" || true)"
  if [ "$actual" != "$expected" ]; then
    echo "FAIL $label: expected $expected occurrence(s), got $actual"
    echo "Needle: $needle"
    cat "$file"
    exit 1
  fi
}

fetch_xml "$BASE_URL/sitemap.xml" /tmp/sitemap-index.headers /tmp/sitemap-index.xml -H 'Host: attacker.invalid'
assert_contains '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' /tmp/sitemap-index.xml "sitemap index namespace"
assert_contains "$PUBLIC_BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-index.xml "configured canonical Collection sitemap origin"
assert_contains "$PUBLIC_BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-index.xml "configured canonical MediaAsset sitemap origin"
assert_absent 'attacker.invalid' /tmp/sitemap-index.xml "forged Host header"

fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-public.headers /tmp/sitemap-public.xml
assert_contains 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' /tmp/sitemap-public.xml "image sitemap namespace"
assert_contains "<loc>$PUBLIC_BASE_URL/collections</loc>" /tmp/sitemap-public.xml "public Collections root"
assert_contains "$PUBLIC_BASE_URL/collections/$CATEGORY_ID" /tmp/sitemap-public.xml "indexable category"
assert_contains "$PUBLIC_BASE_URL/collections/$COLLECTION_ID" /tmp/sitemap-public.xml "indexable collection"
assert_contains "$PUBLIC_BASE_URL/media/$MEDIA_ID/derivatives/v$MEDIA_VERSION/$MEDIA_PROFILE" /tmp/sitemap-public.xml "indexable image derivative"
assert_absent '/api/media' /tmp/sitemap-public.xml "ad-hoc search URL"
assert_absent "$PRIVATE_SENTINEL" /tmp/sitemap-public.xml "private metadata sentinel"
expect_status 404 "$BASE_URL/sitemaps/collections-2.xml" /tmp/sitemap-page-2.html

fetch_xml "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-media.headers /tmp/sitemap-media.xml -H 'Host: attacker.invalid'
assert_contains "<loc>$PUBLIC_BASE_URL/media/$MEDIA_ID</loc>" /tmp/sitemap-media.xml "stable public MediaAsset detail URL"
assert_count 1 "<loc>$PUBLIC_BASE_URL/media/$MEDIA_ID</loc>" /tmp/sitemap-media.xml "multi-membership MediaAsset deduplication"
assert_absent 'attacker.invalid' /tmp/sitemap-media.xml "forged Host header in media sitemap"
assert_absent "$PRIVATE_SENTINEL" /tmp/sitemap-media.xml "private metadata sentinel in media sitemap"
assert_absent '/derivatives/' /tmp/sitemap-media.xml "derivative URL used as MediaAsset identity"
expect_status 404 "$BASE_URL/sitemaps/media-2.xml" /tmp/sitemap-media-page-2.html

php bin/console mediarama:search-index:set collection "$CATEGORY_ID" noindex >/dev/null
php bin/console mediarama:search-index:set collection "$COLLECTION_ID" index >/dev/null
php bin/console mediarama:search-index:set media "$MEDIA_ID" inherit >/dev/null

fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-collection-policy.headers /tmp/sitemap-collection-policy.xml
assert_absent "$PUBLIC_BASE_URL/collections/$CATEGORY_ID" /tmp/sitemap-collection-policy.xml "noindex category"
assert_contains "$PUBLIC_BASE_URL/collections/$COLLECTION_ID" /tmp/sitemap-collection-policy.xml "explicit-index collection"
assert_contains "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-collection-policy.xml "independently indexable media"

fetch_xml "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-media-collection-policy.headers /tmp/sitemap-media-collection-policy.xml
assert_contains "<loc>$PUBLIC_BASE_URL/media/$MEDIA_ID</loc>" /tmp/sitemap-media-collection-policy.xml "Collection noindex does not remove MediaAsset canonical page"

php bin/console mediarama:search-index:set media "$MEDIA_ID" noindex >/dev/null
fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-media-noindex.headers /tmp/sitemap-media-noindex.xml
assert_contains "$PUBLIC_BASE_URL/collections/$COLLECTION_ID" /tmp/sitemap-media-noindex.xml "collection remains indexable"
assert_absent "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-media-noindex.xml "noindex media resource"

fetch_xml "$BASE_URL/sitemap.xml" /tmp/sitemap-media-noindex-index.headers /tmp/sitemap-media-noindex-index.xml
assert_absent "$PUBLIC_BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-media-noindex-index.xml "media sitemap chunk when no MediaAsset is indexable"
expect_status 404 "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-media-noindex-detail.html

php bin/console mediarama:platform:publication-settings --public-publishing=on --search-index-default=noindex >/dev/null
php bin/console mediarama:search-index:set collection "$CATEGORY_ID" inherit >/dev/null
php bin/console mediarama:search-index:set collection "$COLLECTION_ID" inherit >/dev/null
php bin/console mediarama:search-index:set media "$MEDIA_ID" inherit >/dev/null

fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-site-noindex.headers /tmp/sitemap-site-noindex.xml
assert_absent "<loc>$PUBLIC_BASE_URL/collections</loc>" /tmp/sitemap-site-noindex.xml "site-default noindex root"
assert_absent "/collections/$CATEGORY_ID" /tmp/sitemap-site-noindex.xml "inherited noindex category"
assert_absent "/collections/$COLLECTION_ID" /tmp/sitemap-site-noindex.xml "inherited noindex collection"
assert_absent "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-site-noindex.xml "inherited noindex media"

fetch_xml "$BASE_URL/sitemap.xml" /tmp/sitemap-site-noindex-index.headers /tmp/sitemap-site-noindex-index.xml
assert_absent "$PUBLIC_BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-site-noindex-index.xml "inherited noindex MediaAsset sitemap chunk"
expect_status 404 "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-site-noindex-media.html

php bin/console mediarama:search-index:set collection "$COLLECTION_ID" index >/dev/null
php bin/console mediarama:search-index:set media "$MEDIA_ID" index >/dev/null
fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-explicit-index.headers /tmp/sitemap-explicit-index.xml
assert_contains "$PUBLIC_BASE_URL/collections/$COLLECTION_ID" /tmp/sitemap-explicit-index.xml "explicit collection index override"
assert_contains "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-explicit-index.xml "explicit media index override"

fetch_xml "$BASE_URL/sitemap.xml" /tmp/sitemap-explicit-index-index.headers /tmp/sitemap-explicit-index-index.xml
assert_contains "$PUBLIC_BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-explicit-index-index.xml "explicit MediaAsset index override restores media sitemap chunk"
fetch_xml "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-explicit-media.headers /tmp/sitemap-explicit-media.xml
assert_contains "<loc>$PUBLIC_BASE_URL/media/$MEDIA_ID</loc>" /tmp/sitemap-explicit-media.xml "explicit MediaAsset index override"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("UPDATE collections SET password_protected = TRUE WHERE title = 'Fixture Category'");
$db->close();
PHP

fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-private-parent.headers /tmp/sitemap-private-parent.xml
assert_absent "/collections/$COLLECTION_ID" /tmp/sitemap-private-parent.xml "collection behind inaccessible ancestor"
assert_absent "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-private-parent.xml "media behind inaccessible ancestor"
expect_status 404 "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-private-parent-media.html

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("UPDATE collections SET password_protected = FALSE WHERE title = 'Fixture Category'");
$db->executeStatement("UPDATE media_assets SET moderation_state = 'pending_review' WHERE title = 'Fixture Photo'");
$db->close();
PHP

fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-unpublished.headers /tmp/sitemap-unpublished.xml
assert_contains "$PUBLIC_BASE_URL/collections/$COLLECTION_ID" /tmp/sitemap-unpublished.xml "public collection with unpublished media"
assert_absent "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-unpublished.xml "unpublished media"
expect_status 404 "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-unpublished-media.html

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("UPDATE media_assets SET moderation_state = 'published' WHERE title = 'Fixture Photo'");
$db->executeStatement("UPDATE collections SET visibility = 'private' WHERE title = 'Fixture Album'");
$db->close();
PHP

fetch_xml "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-private-collection.headers /tmp/sitemap-private-collection.xml
assert_absent "/collections/$COLLECTION_ID" /tmp/sitemap-private-collection.xml "private collection despite index preference"
assert_absent "/media/$MEDIA_ID/derivatives/" /tmp/sitemap-private-collection.xml "media reachable only through excluded host collection in this fixture"

fetch_xml "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-private-collection-media.headers /tmp/sitemap-private-collection-media.xml
assert_contains "<loc>$PUBLIC_BASE_URL/media/$MEDIA_ID</loc>" /tmp/sitemap-private-collection-media.xml "public MediaAsset remains discoverable through other effective membership"
assert_absent "Fixture Album" /tmp/sitemap-private-collection-media.xml "private Collection title"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("UPDATE collections SET visibility = 'public' WHERE title = 'Fixture Album'");
$db->close();
PHP

php bin/console mediarama:platform:publication-settings --public-publishing=off --search-index-default=noindex >/dev/null
expect_status 404 "$BASE_URL/sitemap.xml" /tmp/sitemap-disabled-index.html
expect_status 404 "$BASE_URL/sitemaps/collections-1.xml" /tmp/sitemap-disabled-chunk.html
expect_status 404 "$BASE_URL/sitemaps/media-1.xml" /tmp/sitemap-disabled-media.html

reset_fixture
kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true
trap - EXIT

echo "Sitemap discovery/privacy checks passed."
