#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${PUBLIC_BASE_URL:?PUBLIC_BASE_URL must be set}"

BASE_URL="http://127.0.0.1:8095"
CANONICAL_ORIGIN="${PUBLIC_BASE_URL%/}"
HOSTILE_HOST="attacker.example"
OWNER_ID="77777777-7777-4777-8777-777777777771"
SMART_ID="77777777-7777-4777-8777-777777777772"
HOST_COLLECTION_ID="77777777-7777-4777-8777-777777777773"
PUBLIC_MATCH_ID="77777777-7777-4777-8777-777777777774"
PRIVATE_MATCH_ID="77777777-7777-4777-8777-777777777775"
PUBLIC_SWAP_ID="77777777-7777-4777-8777-777777777776"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    php bin/console mediarama:platform:deployment-profile private_workspace >/dev/null 2>&1 || true

    php <<'PHP' >/dev/null 2>&1 || true
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$ids = [
    '77777777-7777-4777-8777-777777777774',
    '77777777-7777-4777-8777-777777777775',
    '77777777-7777-4777-8777-777777777776',
];
$db->executeStatement('DELETE FROM media_derivatives WHERE media_id IN (:a, :b, :c)', [
    'a' => $ids[0], 'b' => $ids[1], 'c' => $ids[2],
]);
$db->executeStatement('DELETE FROM collection_media WHERE media_id IN (:a, :b, :c)', [
    'a' => $ids[0], 'b' => $ids[1], 'c' => $ids[2],
]);
$db->delete('collections', ['id' => '77777777-7777-4777-8777-777777777772']);
$db->delete('collections', ['id' => '77777777-7777-4777-8777-777777777773']);
foreach ($ids as $id) {
    $db->delete('media_assets', ['id' => $id]);
}
$db->delete('users', ['id' => '77777777-7777-4777-8777-777777777771']);
$db->close();
PHP
}
trap cleanup EXIT

php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionManagement;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionPublication;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$owner = Uuid::fromString('77777777-7777-4777-8777-777777777771');
$smart = Uuid::fromString('77777777-7777-4777-8777-777777777772');
$host = Uuid::fromString('77777777-7777-4777-8777-777777777773');
$publicMatch = Uuid::fromString('77777777-7777-4777-8777-777777777774');
$privateMatch = Uuid::fromString('77777777-7777-4777-8777-777777777775');
$publicSwap = Uuid::fromString('77777777-7777-4777-8777-777777777776');
$now = '2026-09-28T11:00:00+00:00';

$db->insert('users', [
    'id' => $owner->toRfc4122(),
    'username' => 'public-smart-owner',
    'email' => null,
    'password_hash' => null,
    'display_name' => 'Public Smart Owner',
    'status' => 'active',
    'locale' => 'en',
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => null,
]);

$db->insert('collections', [
    'id' => $host->toRfc4122(),
    'owner_id' => $owner->toRfc4122(),
    'parent_id' => null,
    'cover_media_id' => null,
    'slug' => null,
    'title' => 'Public Smart Host',
    'description' => null,
    'visibility' => 'public',
    'position' => 900,
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => null,
    'password_protected' => false,
    'password_hash' => null,
    'password_hint' => null,
    'password_reset_required' => false,
    'search_index_policy' => 'noindex',
], [
    'password_protected' => ParameterType::BOOLEAN,
    'password_reset_required' => ParameterType::BOOLEAN,
]);

foreach ([
    [$publicMatch, 'PUBLIC SMART MATCH', 'Nikon Z 8', true],
    [$privateMatch, 'PRIVATE SMART SENTINEL', 'Nikon Z 8', false],
    [$publicSwap, 'PUBLIC SMART SWAP', 'Canon R5', true],
] as [$id, $title, $camera, $public]) {
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'smart-public/'.$id->toRfc4122().'/source',
        'original_filename' => 'PRIVATE_SOURCE_'.$id->toRfc4122().'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => $title,
        'description' => $title.' description',
        'captured_at' => '2026-09-28T10:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'published',
        'metadata' => json_encode(['PRIVATE_RAW_METADATA_SENTINEL' => 'secret'], JSON_THROW_ON_ERROR),
        'metadata_provenance' => '{}',
        'creator' => 'Smart Owner',
        'copyright' => null,
        'camera_make' => 'Camera',
        'camera_model' => $camera,
        'lens' => null,
        'iso' => null,
        'aperture' => null,
        'exposure_time' => null,
        'focal_length' => null,
        'latitude' => 48.123,
        'longitude' => 16.456,
        'location_name' => 'PRIVATE LOCATION SENTINEL',
        'search_index_policy' => 'index',
        'public_published_at' => $public ? $now : null,
        'public_updated_at' => $public ? $now : null,
        'public_published_origin' => $public ? 'editorial' : null,
        'public_published_source' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);

    $db->insert('media_derivatives', [
        'id' => Uuid::v7()->toRfc4122(),
        'media_id' => $id->toRfc4122(),
        'kind' => 'image',
        'profile' => 'thumbnail',
        'processing_version' => 1,
        'storage_disk' => 'media',
        'storage_key' => 'smart-public/'.$id->toRfc4122().'/thumbnail.webp',
        'mime_type' => 'image/webp',
        'byte_size' => 1,
        'width' => 480,
        'height' => 360,
        'duration_ms' => null,
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if ($public) {
        $db->insert('collection_media', [
            'collection_id' => $host->toRfc4122(),
            'media_id' => $id->toRfc4122(),
            'position' => 0,
            'added_by' => $owner->toRfc4122(),
            'created_at' => $now,
        ]);
    }
}

$rule = SmartCollectionRule::fromArray([
    'version' => 1,
    'op' => 'and',
    'rules' => [[
        'field' => 'camera_model',
        'operator' => 'contains',
        'value' => 'Nikon',
    ]],
]);

$management = new DbalSmartCollectionManagement($db);
$created = $management->create(
    $owner,
    'Public Smart Fixture',
    'Curated public Smart description.',
    $rule,
);
$db->executeStatement(
    'UPDATE collections SET id = :fixed WHERE id = :created',
    ['fixed' => $smart->toRfc4122(), 'created' => $created->toRfc4122()],
);

$db->close();
PHP

php bin/console mediarama:platform:deployment-profile public_publishing >/tmp/public-smart-profile.txt

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8095 -t public public/index.php >/tmp/mediarama-public-smart-http.log 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
    if curl --silent --show-error --header "Host: $HOSTILE_HOST" "$BASE_URL/collections" >/dev/null 2>&1; then
        break
    fi
    sleep 0.2
done

expect_status() {
    local expected="$1"
    local path="$2"
    local body="$3"
    local headers="$4"
    local status
    status="$(curl --silent --show-error --header "Host: $HOSTILE_HOST" --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$BASE_URL$path")"
    if [ "$status" != "$expected" ]; then
        echo "FAIL expected HTTP $expected, got $status for $path"
        cat /tmp/mediarama-public-smart-http.log || true
        exit 1
    fi
    echo "OK $path -> $expected"
}

expect_status 404 "/collections/$SMART_ID" /tmp/public-smart-private.html /tmp/public-smart-private.headers

SMART_INDEX_POLICY=noindex SMART_COVER_ID="$PUBLIC_MATCH_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionPublication;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

$dsn = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$publisher = new DbalSmartCollectionPublication($db, new DbalPublicPublicationTimelineStore($db));
$publisher->publish(
    Uuid::fromString('77777777-7777-4777-8777-777777777771'),
    Uuid::fromString('77777777-7777-4777-8777-777777777772'),
    SearchIndexPolicy::from((string) getenv('SMART_INDEX_POLICY')),
    Uuid::fromString((string) getenv('SMART_COVER_ID')),
);
$db->close();
PHP

expect_status 200 "/collections/$SMART_ID" /tmp/public-smart.html /tmp/public-smart.headers
grep -F 'Public Smart Fixture' /tmp/public-smart.html >/dev/null
grep -F 'Curated public Smart description.' /tmp/public-smart.html >/dev/null
grep -F 'PUBLIC SMART MATCH' /tmp/public-smart.html >/dev/null
! grep -F 'PRIVATE SMART SENTINEL' /tmp/public-smart.html >/dev/null
! grep -F 'PRIVATE_RAW_METADATA_SENTINEL' /tmp/public-smart.html >/dev/null
! grep -F 'PRIVATE LOCATION SENTINEL' /tmp/public-smart.html >/dev/null
! grep -F 'camera_model' /tmp/public-smart.html >/dev/null
! grep -F '"rules"' /tmp/public-smart.html >/dev/null
grep -i -F 'x-robots-tag: noindex' /tmp/public-smart.headers >/dev/null
grep -F "rel=\"canonical\" href=\"$CANONICAL_ORIGIN/collections/$SMART_ID\"" /tmp/public-smart.html >/dev/null
! grep -F "$HOSTILE_HOST" /tmp/public-smart.html >/dev/null

NOINDEX_SITEMAP_STATUS="$(curl --silent --show-error --header "Host: $HOSTILE_HOST" --output /tmp/public-smart-noindex-collections.xml --write-out '%{http_code}' "$BASE_URL/sitemaps/collections-1.xml")"
if [ "$NOINDEX_SITEMAP_STATUS" = "200" ]; then
    if grep -F "<loc>$CANONICAL_ORIGIN/collections/$SMART_ID</loc>" /tmp/public-smart-noindex-collections.xml >/dev/null; then
        echo "FAIL noindex Smart Collection leaked into Collection sitemap"
        exit 1
    fi
elif [ "$NOINDEX_SITEMAP_STATUS" != "404" ]; then
    echo "FAIL unexpected Collection sitemap status while Smart Collection is noindex: $NOINDEX_SITEMAP_STATUS"
    exit 1
fi
echo "OK noindex Smart Collection stays out of sitemap discovery"

php <<'PHP'
<?php
require 'vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionManagement;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionPublication;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

$dsn = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$owner = Uuid::fromString('77777777-7777-4777-8777-777777777771');
$smart = Uuid::fromString('77777777-7777-4777-8777-777777777772');
$management = new DbalSmartCollectionManagement($db);
$management->update(
    $owner,
    $smart,
    'Public Smart Fixture',
    'Curated public Smart description.',
    SmartCollectionRule::fromArray([
        'version' => 1,
        'op' => 'and',
        'rules' => [[
            'field' => 'camera_model',
            'operator' => 'contains',
            'value' => 'Canon',
        ]],
    ]),
);
(new DbalSmartCollectionPublication($db, new DbalPublicPublicationTimelineStore($db)))->publish(
    $owner,
    $smart,
    SearchIndexPolicy::Index,
    Uuid::fromString('77777777-7777-4777-8777-777777777774'),
);
$db->close();
PHP

expect_status 200 "/collections/$SMART_ID" /tmp/public-smart-swapped.html /tmp/public-smart-swapped.headers
grep -F 'PUBLIC SMART SWAP' /tmp/public-smart-swapped.html >/dev/null
! grep -F 'PUBLIC SMART MATCH' /tmp/public-smart-swapped.html >/dev/null
! grep -F 'PRIVATE SMART SENTINEL' /tmp/public-smart-swapped.html >/dev/null
grep -F "rel=\"canonical\" href=\"$CANONICAL_ORIGIN/collections/$SMART_ID\"" /tmp/public-smart-swapped.html >/dev/null
grep -F "/media/$PUBLIC_MATCH_ID/derivatives/v1/thumbnail" /tmp/public-smart-swapped.html >/dev/null

curl --fail --silent --show-error --header "Host: $HOSTILE_HOST" "$BASE_URL/sitemaps/collections-1.xml" -o /tmp/public-smart-collections.xml
grep -F "<loc>$CANONICAL_ORIGIN/collections/$SMART_ID</loc>" /tmp/public-smart-collections.xml >/dev/null
grep -F "/media/$PUBLIC_SWAP_ID/derivatives/v1/thumbnail" /tmp/public-smart-collections.xml >/dev/null
! grep -F "$PRIVATE_MATCH_ID" /tmp/public-smart-collections.xml >/dev/null
! grep -F 'PRIVATE SMART SENTINEL' /tmp/public-smart-collections.xml >/dev/null
! grep -F 'camera_model' /tmp/public-smart-collections.xml >/dev/null
! grep -F "$HOSTILE_HOST" /tmp/public-smart-collections.xml >/dev/null

php <<'PHP'
<?php
require 'vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionPublication;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

$dsn = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
(new DbalSmartCollectionPublication($db, new DbalPublicPublicationTimelineStore($db)))->unpublish(
    Uuid::fromString('77777777-7777-4777-8777-777777777771'),
    Uuid::fromString('77777777-7777-4777-8777-777777777772'),
);
$db->close();
PHP

expect_status 404 "/collections/$SMART_ID" /tmp/public-smart-unpublished.html /tmp/public-smart-unpublished.headers

echo "Public Smart Collection publication/SEO/privacy checks passed."
