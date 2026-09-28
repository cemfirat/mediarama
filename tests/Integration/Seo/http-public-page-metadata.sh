#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${PUBLIC_BASE_URL:?PUBLIC_BASE_URL must be set}"

BASE_URL="http://127.0.0.1:8087"
CANONICAL_ORIGIN="${PUBLIC_BASE_URL%/}"
HOSTILE_HOST="attacker.example"
SERVER_PID=""

php bin/console mediarama:platform:deployment-profile public_publishing >/tmp/seo-page-profile.txt

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

$values = [
    'category' => (string) $db->fetchOne(
        "SELECT id FROM collections WHERE title = 'Fixture Category'"
    ),
    'collection' => (string) $db->fetchOne(
        "SELECT id FROM collections WHERE title = 'Fixture Album'"
    ),
    'media' => (string) $db->fetchOne(
        "SELECT id FROM media_assets WHERE title = 'Fixture Photo'"
    ),
];
$values['version'] = (string) $db->fetchOne(
    "SELECT MAX(processing_version)
     FROM media_derivatives
     WHERE media_id = :media
       AND kind = 'image'
       AND profile = 'thumbnail'",
    ['media' => $values['media']],
);

foreach ($values as $name => $value) {
    if ($value === '') {
        throw new RuntimeException('Missing SEO fixture value: '.$name);
    }

    file_put_contents('/tmp/seo-page-'.$name, $value);
}

$description = $db->fetchOne(
    "SELECT description FROM collections WHERE title = 'Fixture Album'"
);
if ($description !== 'Album imported by CI') {
    throw new RuntimeException('Unexpected Fixture Album description baseline.');
}

$db->close();
PHP

CATEGORY_ID="$(cat /tmp/seo-page-category)"
COLLECTION_ID="$(cat /tmp/seo-page-collection)"
MEDIA_ID="$(cat /tmp/seo-page-media)"
THUMBNAIL_VERSION="$(cat /tmp/seo-page-version)"
COLLECTION_CANONICAL="$CANONICAL_ORIGIN/collections/$COLLECTION_ID"
IMAGE_URL="$CANONICAL_ORIGIN/media/$MEDIA_ID/derivatives/v$THUMBNAIL_VERSION/thumbnail"

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    php bin/console mediarama:platform:deployment-profile private_workspace >/dev/null 2>&1 || true

    php <<'PHP' >/dev/null 2>&1 || true
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
    "UPDATE collections
     SET description = 'Album imported by CI'
     WHERE title = 'Fixture Album'"
);
$db->executeStatement(
    "UPDATE media_assets
     SET search_index_policy = 'inherit',
         moderation_state = 'published'
     WHERE title = 'Fixture Photo'"
);
$db->close();
PHP
}
trap cleanup EXIT

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8087 -t public public/index.php >/tmp/mediarama-seo-page-http.log 2>&1 &
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
        cat /tmp/mediarama-seo-page-http.log || true
        exit 1
    fi
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

expect_header_contains() {
    local file="$1"
    local value="$2"
    local label="$3"

    if ! grep -i -F "$value" "$file" >/dev/null; then
        echo "FAIL $label"
        echo "Expected header: $value"
        cat "$file"
        exit 1
    fi

    echo "OK $label"
}

cat >/tmp/assert-public-seo-json.php <<'PHP'
<?php

$html = (string) file_get_contents($argv[1]);
$mode = $argv[2];
$expectedCanonical = $argv[3];
$expectedName = $argv[4];
$expectedDescription = $argv[5];
$expectedImage = $argv[6];

if (!preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match)) {
    fwrite(STDERR, "JSON-LD script missing.\n");
    exit(1);
}

$data = json_decode(trim($match[1]), true, flags: JSON_THROW_ON_ERROR);
if (($data['@context'] ?? null) !== 'https://schema.org') {
    fwrite(STDERR, "Unexpected JSON-LD context.\n");
    exit(1);
}

$graph = $data['@graph'] ?? null;
if (!is_array($graph)) {
    fwrite(STDERR, "JSON-LD graph missing.\n");
    exit(1);
}

$findType = static function (string $type) use ($graph): ?array {
    foreach ($graph as $node) {
        if (is_array($node) && ($node['@type'] ?? null) === $type) {
            return $node;
        }
    }

    return null;
};

$page = $findType('CollectionPage');
if (
    $page === null
    || ($page['url'] ?? null) !== $expectedCanonical
    || ($page['name'] ?? null) !== $expectedName
    || ($page['description'] ?? null) !== $expectedDescription
) {
    fwrite(STDERR, "CollectionPage JSON-LD does not match expected public metadata.\n");
    exit(1);
}

$breadcrumb = $findType('BreadcrumbList');
if ($mode === 'detail') {
    $items = $breadcrumb['itemListElement'] ?? null;
    if (
        !is_array($breadcrumb)
        || !is_array($items)
        || count($items) !== 2
        || ($items[0]['name'] ?? null) !== 'Collections'
        || ($items[1]['name'] ?? null) !== $expectedName
        || ($items[1]['item'] ?? null) !== $expectedCanonical
    ) {
        fwrite(STDERR, "Detail BreadcrumbList is invalid.\n");
        exit(1);
    }
} elseif ($breadcrumb !== null) {
    fwrite(STDERR, "Collection index unexpectedly emitted a detail breadcrumb.\n");
    exit(1);
}

$image = $findType('ImageObject');
if ($expectedImage === '-') {
    if ($image !== null || array_key_exists('primaryImageOfPage', $page)) {
        fwrite(STDERR, "JSON-LD exposed an image that is not effectively indexable.\n");
        exit(1);
    }
} else {
    if (
        $image === null
        || ($image['contentUrl'] ?? null) !== $expectedImage
        || !isset($page['primaryImageOfPage']['@id'])
        || ($page['primaryImageOfPage']['@id'] ?? null) !== ($image['@id'] ?? null)
    ) {
        fwrite(STDERR, "Primary ImageObject is missing or inconsistent.\n");
        exit(1);
    }
}

$raw = (string) $match[1];
foreach ([
    'sample.jpg',
    'camera_make',
    'camera_model',
    'latitude',
    'longitude',
    'storage_key',
    'metadata_provenance',
] as $forbidden) {
    if (str_contains($raw, $forbidden)) {
        fwrite(STDERR, "Structured metadata leaked forbidden source field: ".$forbidden."\n");
        exit(1);
    }
}

echo "OK structured metadata ".$mode." boundary\n";
PHP

fetch_page "/collections" /tmp/seo-index.html /tmp/seo-index.headers
expect_contains /tmp/seo-index.html '<title>Collections · Mediarama</title>' "Collection index title"
expect_contains /tmp/seo-index.html '<meta name="description" content="Browse public photo and video collections on Mediarama.">' "Collection index description"
expect_tag_url /tmp/seo-index.html 'rel="canonical"' "$CANONICAL_ORIGIN/collections" "Collection index canonical"
expect_tag_url /tmp/seo-index.html 'property="og:url"' "$CANONICAL_ORIGIN/collections" "Collection index Open Graph URL"
expect_contains /tmp/seo-index.html '<meta property="og:type" content="website">' "Collection index Open Graph type"
expect_contains /tmp/seo-index.html '<meta property="og:site_name" content="Mediarama">' "Collection index Open Graph site name"
expect_absent /tmp/seo-index.html "$HOSTILE_HOST" "Host header cannot influence Collection index metadata"
php /tmp/assert-public-seo-json.php     /tmp/seo-index.html     index     "$CANONICAL_ORIGIN/collections"     "Collections"     "Browse public photo and video collections on Mediarama."     "-"

fetch_page "/collections/$COLLECTION_ID" /tmp/seo-detail.html /tmp/seo-detail.headers
expect_contains /tmp/seo-detail.html '<title>Fixture Album · Mediarama</title>' "Collection detail title"
expect_contains /tmp/seo-detail.html '<meta name="description" content="Album imported by CI">' "Collection detail public description"
expect_tag_url /tmp/seo-detail.html 'rel="canonical"' "$COLLECTION_CANONICAL" "Collection detail canonical"
expect_tag_url /tmp/seo-detail.html 'property="og:url"' "$COLLECTION_CANONICAL" "Collection detail Open Graph URL"
expect_tag_url /tmp/seo-detail.html 'property="og:image"' "$IMAGE_URL" "Collection detail Open Graph cover"
expect_contains /tmp/seo-detail.html '<meta property="og:image:alt" content="Cover image for Fixture Album">' "Collection detail Open Graph cover alt"
expect_absent /tmp/seo-detail.html "$HOSTILE_HOST" "Host header cannot influence Collection detail metadata"
php /tmp/assert-public-seo-json.php     /tmp/seo-detail.html     detail     "$COLLECTION_CANONICAL"     "Fixture Album"     "Album imported by CI"     "$IMAGE_URL"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("UPDATE collections SET description = NULL WHERE title = 'Fixture Album'");
$db->close();
PHP

fetch_page "/collections/$COLLECTION_ID" /tmp/seo-fallback.html /tmp/seo-fallback.headers
expect_contains /tmp/seo-fallback.html '<meta name="description" content="Browse photos and videos in Fixture Album.">' "Empty Collection description uses deterministic fallback"

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
     SET description = 'Album imported by CI',
         search_index_policy = 'noindex'
     WHERE title = 'Fixture Album'"
);
$db->close();
PHP

fetch_page "/collections/$COLLECTION_ID" /tmp/seo-noindex.html /tmp/seo-noindex.headers
expect_header_contains /tmp/seo-noindex.headers 'X-Robots-Tag: noindex' "Noindex Collection keeps robots boundary"
expect_tag_url /tmp/seo-noindex.html 'rel="canonical"' "$COLLECTION_CANONICAL" "Noindex Collection keeps canonical"
expect_tag_url /tmp/seo-noindex.html 'property="og:image"' "$IMAGE_URL" "Noindex Collection keeps social cover"
expect_absent /tmp/seo-noindex.html 'application/ld+json' "Noindex Collection omits index-oriented JSON-LD"

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
    "UPDATE media_assets SET search_index_policy = 'noindex' WHERE title = 'Fixture Photo'"
);
$db->close();
PHP

fetch_page "/collections/$COLLECTION_ID" /tmp/seo-media-noindex.html /tmp/seo-media-noindex.headers
expect_tag_url /tmp/seo-media-noindex.html 'property="og:image"' "$IMAGE_URL" "Public noindex cover remains available for social preview"
php /tmp/assert-public-seo-json.php     /tmp/seo-media-noindex.html     detail     "$COLLECTION_CANONICAL"     "Fixture Album"     "Album imported by CI"     "-"

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE media_assets SET search_index_policy = 'inherit' WHERE title = 'Fixture Photo'"
);
$db->executeStatement(
    "UPDATE collections SET password_protected = TRUE WHERE title = 'Fixture Category'"
);
$db->close();
PHP

PRIVATE_STATUS="$(curl --silent --show-error     --header "Host: $HOSTILE_HOST"     --output /tmp/seo-private.html     --write-out '%{http_code}'     "$BASE_URL/collections/$COLLECTION_ID")"
if [ "$PRIVATE_STATUS" != "404" ]; then
    echo "FAIL inaccessible Collection must return 404, got $PRIVATE_STATUS"
    cat /tmp/mediarama-seo-page-http.log || true
    exit 1
fi
expect_absent /tmp/seo-private.html "Fixture Album" "Inaccessible Collection does not expose title metadata"
expect_absent /tmp/seo-private.html "$COLLECTION_CANONICAL" "Inaccessible Collection does not expose canonical metadata"

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
$db->close();
PHP

php bin/console mediarama:platform:publication-settings     --search-index-default=noindex >/tmp/seo-root-noindex-setting.txt

fetch_page "/collections" /tmp/seo-root-noindex.html /tmp/seo-root-noindex.headers
expect_header_contains /tmp/seo-root-noindex.headers 'X-Robots-Tag: noindex' "Noindex Collection index keeps robots boundary"
expect_tag_url /tmp/seo-root-noindex.html 'rel="canonical"' "$CANONICAL_ORIGIN/collections" "Noindex Collection index keeps canonical"
expect_absent /tmp/seo-root-noindex.html 'application/ld+json' "Noindex Collection index omits index-oriented JSON-LD"

echo "Public Collection canonical/social/structured metadata checks passed."
