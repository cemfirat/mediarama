#!/usr/bin/env bash
set -euo pipefail

: "$DATABASE_URL"

BASE_URL="http://127.0.0.1:8098"
OWNER_ID="76666666-6666-4666-8666-666666666661"
OTHER_ID="76666666-6666-4666-8666-666666666662"
MEDIA_ID="76666666-6666-4666-8666-666666666669"
BATCH_MEDIA_ID="76666666-6666-4666-8666-666666666670"
BATCH_TAG_ID="76666666-6666-4666-8666-666666666671"
PASSWORD="mediarama-metadata-workspace-ci"
OWNER_JAR="/tmp/metadata-workspace-owner.cookies"
OTHER_JAR="/tmp/metadata-workspace-other.cookies"
BEFORE="/tmp/metadata-workspace-before.json"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    php <<'PHP' >/dev/null 2>&1 || true
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);
$db->delete('media_assets', ['id' => '76666666-6666-4666-8666-666666666669']);
$db->delete('media_assets', ['id' => '76666666-6666-4666-8666-666666666670']);
$db->delete('tags', ['id' => '76666666-6666-4666-8666-666666666671']);
$db->delete('users', ['id' => '76666666-6666-4666-8666-666666666661']);
$db->delete('users', ['id' => '76666666-6666-4666-8666-666666666662']);
$db->close();
PHP

    rm -f "$OWNER_JAR" "$OTHER_JAR" "$BEFORE"
}
trap cleanup EXIT

cleanup
trap cleanup EXIT

php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$password = password_hash('mediarama-metadata-workspace-ci', PASSWORD_DEFAULT);
$now = '2026-09-30T20:30:00+00:00';

foreach ([
    ['76666666-6666-4666-8666-666666666661', 'metadata-workspace-owner'],
    ['76666666-6666-4666-8666-666666666662', 'metadata-workspace-other'],
] as [$id, $username]) {
    $db->insert('users', [
        'id' => $id,
        'username' => $username,
        'email' => null,
        'password_hash' => $password,
        'display_name' => $username,
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}

$db->insert('media_assets', [
    'id' => '76666666-6666-4666-8666-666666666669',
    'owner_id' => '76666666-6666-4666-8666-666666666661',
    'storage_disk' => 'media',
    'storage_key' => 'PRIVATE_STORAGE_SENTINEL/original.jpg',
    'original_filename' => 'owner-source.jpg',
    'mime_type' => 'image/jpeg',
    'media_type' => 'image',
    'byte_size' => 12345,
    'checksum_sha256' => hash('sha256', 'metadata-workspace-fixture'),
    'width' => 1600,
    'height' => 1200,
    'duration_ms' => null,
    'title' => 'CURRENT_TITLE_SENTINEL',
    'description' => 'Current description',
    'captured_at' => '2026-09-29T10:15:00+00:00',
    'processing_state' => 'ready',
    'moderation_state' => 'draft',
    'metadata' => json_encode([
        'exif' => [
            'Make' => 'Fixture Camera',
            'Model' => 'Fixture Model',
            'GPSLatitude' => 48.123456,
            'GPSLongitude' => 16.654321,
            'SerialNumber' => 'DEVICE_SERIAL_SENTINEL',
        ],
        'iptc' => [
            'Caption-Abstract' => 'IPTC_SOURCE_CAPTION_SENTINEL',
            'Keywords' => ['workspace', 'fixture'],
        ],
        'xmp' => [
            'Title' => 'SOURCE_TITLE_SENTINEL',
            'UnknownNamespaceField' => 'RAW_UNKNOWN_SENTINEL',
        ],
        'icc' => [
            'ProfileDescription' => 'Display P3',
        ],
        'technical' => [
            'FileType' => 'JPEG',
            'MIMEType' => 'image/jpeg',
        ],
        'LegacyTopLevelField' => 'LEGACY_UNKNOWN_SENTINEL',
    ], JSON_THROW_ON_ERROR),
    'metadata_provenance' => json_encode([
        'title' => 'user',
        'description' => 'user',
        'captured_at' => 'embedded',
        'creator' => 'embedded',
        'camera_make' => 'embedded',
        'camera_model' => 'embedded',
        'latitude' => 'embedded',
        'longitude' => 'embedded',
        'location_name' => 'user',
    ], JSON_THROW_ON_ERROR),
    'creator' => 'PRIVATE CREATOR SENTINEL',
    'copyright' => 'Fixture Rights',
    'camera_make' => 'Fixture Camera',
    'camera_model' => 'Fixture Model',
    'lens' => '35mm',
    'iso' => 200,
    'aperture' => '2.8',
    'exposure_time' => '1/125',
    'focal_length' => '35 mm',
    'latitude' => 48.123456,
    'longitude' => 16.654321,
    'location_name' => 'PRIVATE LOCATION SENTINEL',
    'search_index_policy' => 'inherit',
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => null,
]);

$batchMedia = $db->fetchAssociative(
    'SELECT * FROM media_assets WHERE id = :id',
    ['id' => '76666666-6666-4666-8666-666666666669'],
);
if ($batchMedia === false) {
    throw new RuntimeException('Metadata workspace fixture asset is missing.');
}
unset($batchMedia['search_document']);
$batchMedia['id'] = '76666666-6666-4666-8666-666666666670';
$batchMedia['storage_key'] = 'PRIVATE_STORAGE_SENTINEL/batch-original.jpg';
$batchMedia['original_filename'] = 'batch-owner-source.jpg';
$batchMedia['checksum_sha256'] = hash('sha256', 'metadata-workspace-batch-fixture');
$batchMedia['title'] = 'Batch keyword fixture';
$db->insert('media_assets', $batchMedia);
$db->insert('tags', [
    'id' => '76666666-6666-4666-8666-666666666671',
    'slug' => 'metadata-workspace-existing-batch',
    'name' => 'Existing batch keyword',
    'created_at' => $now,
    'updated_at' => $now,
]);
$db->insert('media_tags', [
    'media_id' => '76666666-6666-4666-8666-666666666670',
    'tag_id' => '76666666-6666-4666-8666-666666666671',
    'source' => 'coppermine',
]);

$before = $db->fetchAssociative(
    "SELECT
        title,
        description,
        metadata::text AS metadata,
        metadata_provenance::text AS metadata_provenance,
        latitude::text AS latitude,
        longitude::text AS longitude,
        updated_at::text AS updated_at
     FROM media_assets
     WHERE id = :id",
    ['id' => '76666666-6666-4666-8666-666666666669'],
);
file_put_contents(
    '/tmp/metadata-workspace-before.json',
    json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
);

$db->close();
PHP

APP_ENV=test APP_DEBUG=0 php -S 127.0.0.1:8098 -t public public/index.php >/tmp/mediarama-metadata-workspace-http.log 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
    if curl --silent --show-error "$BASE_URL/login" >/dev/null 2>&1; then
        break
    fi
    sleep 0.2
done

expect_status() {
    local expected="$1"
    local actual="$2"
    local label="$3"

    if [ "$actual" != "$expected" ]; then
        echo "FAIL $label: expected HTTP $expected, got $actual"
        cat /tmp/mediarama-metadata-workspace-http.log || true
        exit 1
    fi

    echo "OK $label"
}

expect_contains() {
    local file="$1"
    local expected="$2"
    local label="$3"

    if ! grep -F "$expected" "$file" >/dev/null; then
        echo "FAIL $label: expected text was not present"
        exit 1
    fi

    echo "OK $label"
}

expect_keyword_checkbox() {
    local file="$1"
    local media_id="$2"

    php -r '
        $html = (string) file_get_contents($argv[1]);
        $id = preg_quote($argv[2], "~");
        $pattern = "~<input\\b(?=[^>]*\\bform=\"metadata-keyword-batch-form\")(?=[^>]*\\bname=\"media_ids\\[\\]\")(?=[^>]*\\bvalue=\"".$id."\")[^>]*>~s";
        if (!preg_match($pattern, $html)) {
            fwrite(STDERR, "Batch keyword selection control missing for asset.\n");
            exit(1);
        }
    ' "$file" "$media_id"

    echo "OK owner asset has batch selection control"
}

login() {
    local username="$1"
    local jar="$2"
    local page="/tmp/metadata-workspace-login-$username.html"

    rm -f "$jar"
    curl --fail --silent --show-error --cookie "$jar" --cookie-jar "$jar" "$BASE_URL/login" -o "$page"

    local token
    token="$(php -r '
        $html = (string) file_get_contents($argv[1]);
        if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
            fwrite(STDERR, "Login CSRF token missing.\n");
            exit(1);
        }
        echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$page")"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output /tmp/metadata-workspace-login-post.html         --write-out '%{http_code}'         --data-urlencode "_username=$username"         --data-urlencode "_password=$PASSWORD"         --data-urlencode "_csrf_token=$token"         "$BASE_URL/login"
}

form_token() {
    local page="$1"
    local action_fragment="$2"

    php -r '
        $html = (string) file_get_contents($argv[1]);
        $action = preg_quote($argv[2], "~");
        if (!preg_match("~<form[^>]*action=\"[^\"]*".$action."[^\"]*\"[^>]*>(.*?)</form>~s", $html, $form)) {
            fwrite(STDERR, "Target form not found: ".$argv[2]."\n");
            exit(1);
        }
        if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $form[1], $token)) {
            fwrite(STDERR, "Form CSRF token missing.\n");
            exit(1);
        }
        echo html_entity_decode($token[1], ENT_QUOTES | ENT_HTML5);
    ' "$page" "$action_fragment"
}

UNAUTH_STATUS="$(curl --silent --show-error     --output /tmp/metadata-workspace-unauth.html     --write-out '%{http_code}'     "$BASE_URL/library/media/$MEDIA_ID/metadata")"
expect_status 302 "$UNAUTH_STATUS" "metadata workspace requires authentication"

expect_status 302 "$(login metadata-workspace-owner "$OWNER_JAR")" "metadata workspace owner can authenticate"
expect_status 302 "$(login metadata-workspace-other "$OTHER_JAR")" "second user can authenticate"

LIBRARY_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR"     --output /tmp/metadata-workspace-library.html     --write-out '%{http_code}'     "$BASE_URL/library")"
expect_status 200 "$LIBRARY_STATUS" "owner can open Library"
grep -F "/library/media/$MEDIA_ID/metadata" /tmp/metadata-workspace-library.html >/dev/null

OWNER_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR"     --dump-header /tmp/metadata-workspace.headers     --output /tmp/metadata-workspace.html     --write-out '%{http_code}'     "$BASE_URL/library/media/$MEDIA_ID/metadata")"
expect_status 200 "$OWNER_STATUS" "owner can inspect complete metadata workspace"

grep -i -F 'x-robots-tag: noindex, nofollow' /tmp/metadata-workspace.headers >/dev/null
grep -i -E '^cache-control:.*no-store' /tmp/metadata-workspace.headers >/dev/null
grep -F 'Current Mediarama values' /tmp/metadata-workspace.html >/dev/null
grep -F 'Current Mediarama value' /tmp/metadata-workspace.html >/dev/null
grep -F 'Provenance' /tmp/metadata-workspace.html >/dev/null
grep -F 'Source snapshot' /tmp/metadata-workspace.html >/dev/null
grep -F 'Source value' /tmp/metadata-workspace.html >/dev/null
grep -F 'EXIF / Camera' /tmp/metadata-workspace.html >/dev/null
grep -F 'IPTC / Descriptive' /tmp/metadata-workspace.html >/dev/null
grep -F 'XMP / Workflow' /tmp/metadata-workspace.html >/dev/null
grep -F 'ICC / Color' /tmp/metadata-workspace.html >/dev/null
grep -F 'File / Technical' /tmp/metadata-workspace.html >/dev/null
grep -F 'Other source metadata' /tmp/metadata-workspace.html >/dev/null
grep -F 'CURRENT_TITLE_SENTINEL' /tmp/metadata-workspace.html >/dev/null
grep -F 'SOURCE_TITLE_SENTINEL' /tmp/metadata-workspace.html >/dev/null
grep -F 'RAW_UNKNOWN_SENTINEL' /tmp/metadata-workspace.html >/dev/null
grep -F 'LEGACY_UNKNOWN_SENTINEL' /tmp/metadata-workspace.html >/dev/null
grep -F 'Display P3' /tmp/metadata-workspace.html >/dev/null
grep -F 'DEVICE_SERIAL_SENTINEL' /tmp/metadata-workspace.html >/dev/null
grep -F '48.123456' /tmp/metadata-workspace.html >/dev/null
grep -F '16.654321' /tmp/metadata-workspace.html >/dev/null
grep -F 'Privacy-sensitive' /tmp/metadata-workspace.html >/dev/null
grep -F '>user<' /tmp/metadata-workspace.html >/dev/null
if grep -F 'PRIVATE_STORAGE_SENTINEL' /tmp/metadata-workspace.html >/dev/null; then
    echo "FAIL storage identity leaked into metadata workspace"
    exit 1
fi

OTHER_STATUS="$(curl --silent --show-error     --cookie "$OTHER_JAR" --cookie-jar "$OTHER_JAR"     --output /tmp/metadata-workspace-other.html     --write-out '%{http_code}'     "$BASE_URL/library/media/$MEDIA_ID/metadata")"
expect_status 404 "$OTHER_STATUS" "non-owner cannot inspect raw metadata workspace"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);
$after = $db->fetchAssociative(
    "SELECT
        title,
        description,
        metadata::text AS metadata,
        metadata_provenance::text AS metadata_provenance,
        latitude::text AS latitude,
        longitude::text AS longitude,
        updated_at::text AS updated_at
     FROM media_assets
     WHERE id = :id",
    ['id' => '76666666-6666-4666-8666-666666666669'],
);
$before = json_decode(
    (string) file_get_contents('/tmp/metadata-workspace-before.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

if ($after !== $before) {
    fwrite(STDERR, "Metadata workspace read mutated persisted media state.\n");
    fwrite(STDERR, json_encode(['before' => $before, 'after' => $after], JSON_PRETTY_PRINT).PHP_EOL);
    exit(1);
}

$db->close();
echo "OK metadata workspace GET remains read-only\n";
PHP

EDIT_TOKEN="$(form_token /tmp/metadata-workspace.html "/library/media/$MEDIA_ID/metadata/edit")"

NO_CSRF_EDIT_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/metadata-workspace-edit-no-csrf.html \
    --write-out '%{http_code}' \
    --data-urlencode 'actions[title]=set' \
    --data-urlencode 'values[title]=FORBIDDEN_WITHOUT_CSRF' \
    "$BASE_URL/library/media/$MEDIA_ID/metadata/edit")"
expect_status 403 "$NO_CSRF_EDIT_STATUS" "metadata edit rejects missing CSRF"

NON_OWNER_EDIT_STATUS="$(curl --silent --show-error \
    --cookie "$OTHER_JAR" --cookie-jar "$OTHER_JAR" \
    --output /tmp/metadata-workspace-edit-other.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$EDIT_TOKEN" \
    --data-urlencode 'actions[title]=set' \
    --data-urlencode 'values[title]=FORBIDDEN_NON_OWNER_EDIT' \
    "$BASE_URL/library/media/$MEDIA_ID/metadata/edit")"
expect_status 404 "$NON_OWNER_EDIT_STATUS" "non-owner cannot mutate metadata workspace"

EDIT_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --dump-header /tmp/metadata-workspace-edit.headers \
    --output /tmp/metadata-workspace-edit.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$EDIT_TOKEN" \
    --data-urlencode 'actions[title]=set' \
    --data-urlencode 'values[title]=  EDITED_TITLE_SENTINEL  ' \
    --data-urlencode 'actions[description]=clear' \
    --data-urlencode 'actions[creator]=keep' \
    --data-urlencode 'values[creator]=SHOULD_NOT_REPLACE_CREATOR' \
    --data-urlencode 'actions[copyright]=set' \
    --data-urlencode 'values[copyright]=  Updated Workspace Rights  ' \
    --data-urlencode 'actions[location_name]=clear' \
    "$BASE_URL/library/media/$MEDIA_ID/metadata/edit")"
expect_status 302 "$EDIT_STATUS" "owner can explicitly set and clear canonical metadata"

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library/media/$MEDIA_ID/metadata?saved=1" \
    -o /tmp/metadata-workspace-after-edit.html

grep -F 'Canonical metadata changes were saved.' /tmp/metadata-workspace-after-edit.html >/dev/null
grep -F 'EDITED_TITLE_SENTINEL' /tmp/metadata-workspace-after-edit.html >/dev/null
grep -F 'Updated Workspace Rights' /tmp/metadata-workspace-after-edit.html >/dev/null
grep -F 'PRIVATE CREATOR SENTINEL' /tmp/metadata-workspace-after-edit.html >/dev/null
grep -F 'SOURCE_TITLE_SENTINEL' /tmp/metadata-workspace-after-edit.html >/dev/null
grep -F 'RAW_UNKNOWN_SENTINEL' /tmp/metadata-workspace-after-edit.html >/dev/null
if grep -F 'SHOULD_NOT_REPLACE_CREATOR' /tmp/metadata-workspace-after-edit.html >/dev/null; then
    echo "FAIL Keep action replaced creator"
    exit 1
fi
if grep -F 'PRIVATE_STORAGE_SENTINEL' /tmp/metadata-workspace-after-edit.html >/dev/null; then
    echo "FAIL storage identity leaked after metadata edit"
    exit 1
fi

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);
$row = $db->fetchAssociative(
    "SELECT
        title,
        description,
        creator,
        copyright,
        location_name,
        latitude::text AS latitude,
        longitude::text AS longitude,
        storage_key,
        original_filename,
        metadata::text AS metadata,
        metadata_provenance::text AS metadata_provenance,
        updated_at::text AS updated_at
     FROM media_assets
     WHERE id = :id",
    ['id' => '76666666-6666-4666-8666-666666666669'],
);
$before = json_decode(
    (string) file_get_contents('/tmp/metadata-workspace-before.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$provenance = json_decode(
    (string) $row['metadata_provenance'],
    true,
    flags: JSON_THROW_ON_ERROR,
);

$checks = [
    'title set' => $row['title'] === 'EDITED_TITLE_SENTINEL',
    'description explicitly cleared' => $row['description'] === null,
    'creator kept' => $row['creator'] === 'PRIVATE CREATOR SENTINEL',
    'copyright set' => $row['copyright'] === 'Updated Workspace Rights',
    'location explicitly cleared' => $row['location_name'] === null,
    'latitude unchanged' => $row['latitude'] === '48.123456',
    'longitude unchanged' => $row['longitude'] === '16.654321',
    'storage identity unchanged' => $row['storage_key'] === 'PRIVATE_STORAGE_SENTINEL/original.jpg',
    'original filename unchanged' => $row['original_filename'] === 'owner-source.jpg',
    'source snapshot unchanged' => $row['metadata'] === $before['metadata'],
    'title provenance user' => ($provenance['title'] ?? null) === 'user',
    'description clear provenance user' => ($provenance['description'] ?? null) === 'user',
    'creator provenance kept' => ($provenance['creator'] ?? null) === 'embedded',
    'copyright provenance user' => ($provenance['copyright'] ?? null) === 'user',
    'location clear provenance user' => ($provenance['location_name'] ?? null) === 'user',
    'updated_at advanced' => $row['updated_at'] !== $before['updated_at'],
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL ".$label."\n");
        fwrite(STDERR, json_encode($row, JSON_PRETTY_PRINT).PHP_EOL);
        exit(1);
    }
    echo "OK ".$label."\n";
}

$db->close();
PHP

LIBRARY_AFTER_EDIT="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/metadata-workspace-library-after-edit.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library?q=EDITED_TITLE_SENTINEL")"
expect_status 200 "$LIBRARY_AFTER_EDIT" "edited title participates in generated search document"
grep -F 'EDITED_TITLE_SENTINEL' /tmp/metadata-workspace-library-after-edit.html >/dev/null

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library" \
    -o /tmp/metadata-workspace-library-batch.html
expect_contains /tmp/metadata-workspace-library-batch.html 'Batch edit keywords' "Library renders batch keyword form"
expect_keyword_checkbox /tmp/metadata-workspace-library-batch.html "$BATCH_MEDIA_ID"
BATCH_PREVIEW_TOKEN="$(form_token /tmp/metadata-workspace-library-batch.html "/library/metadata/keywords/preview")"

BATCH_PREVIEW_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/metadata-workspace-keyword-preview.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$BATCH_PREVIEW_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_ID" \
    --data-urlencode "media_ids[]=$BATCH_MEDIA_ID" \
    --data-urlencode 'keyword_action=append' \
    --data-urlencode $'keyword_values=Batch, phrase\nshared' \
    "$BASE_URL/library/metadata/keywords/preview")"
expect_status 200 "$BATCH_PREVIEW_STATUS" "owner can preview selected batch keyword changes"
expect_contains /tmp/metadata-workspace-keyword-preview.html '2 selected MediaAssets' "preview reports selected and changed asset counts"
expect_contains /tmp/metadata-workspace-keyword-preview.html 'Batch, phrase' "preview retains comma-containing keyword"
expect_contains /tmp/metadata-workspace-keyword-preview.html 'Existing batch keyword' "preview includes current keyword values"

BATCH_APPLY_TOKEN="$(form_token /tmp/metadata-workspace-keyword-preview.html "/library/metadata/keywords/apply")"
BATCH_APPLY_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/metadata-workspace-keyword-apply.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$BATCH_APPLY_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_ID" \
    --data-urlencode "media_ids[]=$BATCH_MEDIA_ID" \
    --data-urlencode 'keyword_action=append' \
    --data-urlencode 'keyword_values[]=Batch, phrase' \
    --data-urlencode 'keyword_values[]=shared' \
    "$BASE_URL/library/metadata/keywords/apply")"
expect_status 302 "$BATCH_APPLY_STATUS" "owner can confirm batch keyword changes"

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library?metadata_batch_saved=2" \
    -o /tmp/metadata-workspace-library-batch-saved.html
expect_contains /tmp/metadata-workspace-library-batch-saved.html 'Keywords updated for 2 MediaAssets.' "Library reports confirmed batch changes"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);
$rows = $db->fetchAllAssociative(
    'SELECT mt.media_id, t.name, mt.source
     FROM media_tags mt
     JOIN tags t ON t.id = mt.tag_id
     WHERE mt.media_id IN (:first, :second)
     ORDER BY mt.media_id, t.name',
    [
        'first' => '76666666-6666-4666-8666-666666666669',
        'second' => '76666666-6666-4666-8666-666666666670',
    ],
);
$memberships = [];
foreach ($rows as $row) {
    $memberships[(string) $row['media_id']][(string) $row['name']] = (string) $row['source'];
}
$checks = [
    'first asset receives appended phrase' => ($memberships['76666666-6666-4666-8666-666666666669']['Batch, phrase'] ?? null) === 'manual',
    'first asset receives appended keyword' => ($memberships['76666666-6666-4666-8666-666666666669']['shared'] ?? null) === 'manual',
    'second asset receives appended phrase' => ($memberships['76666666-6666-4666-8666-666666666670']['Batch, phrase'] ?? null) === 'manual',
    'second asset receives appended keyword' => ($memberships['76666666-6666-4666-8666-666666666670']['shared'] ?? null) === 'manual',
    'existing membership provenance is preserved' => ($memberships['76666666-6666-4666-8666-666666666670']['Existing batch keyword'] ?? null) === 'coppermine',
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL ".$label.PHP_EOL);
        exit(1);
    }
    echo "OK ".$label.PHP_EOL;
}
$db->close();
PHP

echo "Metadata workspace inspection, editing and batch keyword checks passed."
