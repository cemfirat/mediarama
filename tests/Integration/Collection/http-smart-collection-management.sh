#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"

BASE_URL="http://127.0.0.1:8094"
OWNER_ID="66666666-6666-4666-8666-666666666661"
OTHER_ID="66666666-6666-4666-8666-666666666662"
MEDIA_ID="66666666-6666-4666-8666-666666666663"
PASSWORD="mediarama-smart-management-ci"
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
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "DELETE FROM collections WHERE owner_id IN (:owner, :other)",
    [
        'owner' => '66666666-6666-4666-8666-666666666661',
        'other' => '66666666-6666-4666-8666-666666666662',
    ],
);
$db->delete('media_assets', ['id' => '66666666-6666-4666-8666-666666666663']);
$db->delete('tags', ['id' => '66666666-6666-4666-8666-666666666664']);
$db->delete('users', ['id' => '66666666-6666-4666-8666-666666666661']);
$db->delete('users', ['id' => '66666666-6666-4666-8666-666666666662']);
$db->close();
PHP
}
trap cleanup EXIT

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$db->executeStatement(
    "DELETE FROM collections WHERE owner_id IN (:owner, :other)",
    [
        'owner' => '66666666-6666-4666-8666-666666666661',
        'other' => '66666666-6666-4666-8666-666666666662',
    ],
);
$db->delete('media_assets', ['id' => '66666666-6666-4666-8666-666666666663']);
$db->delete('tags', ['id' => '66666666-6666-4666-8666-666666666664']);
$db->delete('users', ['id' => '66666666-6666-4666-8666-666666666661']);
$db->delete('users', ['id' => '66666666-6666-4666-8666-666666666662']);

$password = password_hash(
    'mediarama-smart-management-ci',
    PASSWORD_BCRYPT,
    ['cost' => 4],
);
if (!is_string($password)) {
    throw new RuntimeException('Unable to hash Smart management test password.');
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);

foreach ([
    ['66666666-6666-4666-8666-666666666661', 'smart-http-owner'],
    ['66666666-6666-4666-8666-666666666662', 'smart-http-other'],
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
    'id' => '66666666-6666-4666-8666-666666666663',
    'owner_id' => '66666666-6666-4666-8666-666666666661',
    'storage_disk' => 'media',
    'storage_key' => 'smart-http/owner-photo',
    'original_filename' => 'smart-owner.jpg',
    'mime_type' => 'image/jpeg',
    'media_type' => 'image',
    'byte_size' => 1,
    'checksum_sha256' => str_repeat('6', 64),
    'width' => 1200,
    'height' => 800,
    'duration_ms' => null,
    'title' => 'Alice Photo',
    'description' => null,
    'captured_at' => '2026-09-15T10:00:00+00:00',
    'processing_state' => 'ready',
    'moderation_state' => 'published',
    'metadata' => '{}',
    'creator' => 'Alice Example',
    'camera_make' => 'Nikon',
    'camera_model' => 'Nikon Z 8',
    'lens' => '35mm',
    'location_name' => 'Vienna',
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => null,
]);

$db->insert('tags', [
    'id' => '66666666-6666-4666-8666-666666666664',
    'slug' => 'wedding',
    'name' => 'Wedding',
    'created_at' => $now,
    'updated_at' => $now,
]);
$db->insert('media_tags', [
    'media_id' => '66666666-6666-4666-8666-666666666663',
    'tag_id' => '66666666-6666-4666-8666-666666666664',
    'source' => 'manual',
]);
$db->insert('ratings', [
    'user_id' => '66666666-6666-4666-8666-666666666661',
    'media_id' => '66666666-6666-4666-8666-666666666663',
    'value' => 5,
    'created_at' => $now,
    'updated_at' => $now,
]);

$db->close();
PHP

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8094 -t public public/index.php >/tmp/mediarama-smart-management-http.log 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
    if curl --fail --silent "$BASE_URL/login" >/dev/null 2>&1; then
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
        cat /tmp/mediarama-smart-management-http.log || true
        exit 1
    fi

    echo "OK $label"
}

login() {
    local username="$1"
    local jar="$2"
    local page="/tmp/smart-login-$username.html"

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

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output /tmp/smart-login-post.html         --write-out '%{http_code}'         --data-urlencode "_username=$username"         --data-urlencode "_password=$PASSWORD"         --data-urlencode "_csrf_token=$token"         "$BASE_URL/login"
}

form_token() {
    local page="$1"
    local action_fragment="$2"

    php -r '
        $html = (string) file_get_contents($argv[1]);
        $action = preg_quote($argv[2], "~");
        if (!preg_match("~<form[^>]*action=\"[^\"]*".$action."[^\"]*\"[^>]*>(.*?)</form>~s", $html, $form)) {
            fwrite(STDERR, "Target form not found.\n");
            exit(1);
        }
        if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $form[1], $token)) {
            fwrite(STDERR, "Form CSRF token missing.\n");
            exit(1);
        }
        echo html_entity_decode($token[1], ENT_QUOTES | ENT_HTML5);
    ' "$page" "$action_fragment"
}

first_token() {
    local page="$1"

    php -r '
        $html = (string) file_get_contents($argv[1]);
        if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $token)) {
            fwrite(STDERR, "CSRF token missing.\n");
            exit(1);
        }
        echo html_entity_decode($token[1], ENT_QUOTES | ENT_HTML5);
    ' "$page"
}

ANON_STATUS="$(curl --silent --show-error --output /tmp/smart-anon.html --write-out '%{http_code}' "$BASE_URL/library")"
expect_status 302 "$ANON_STATUS" "anonymous library request redirects to authentication"

OWNER_JAR=/tmp/smart-owner.cookies
expect_status 302 "$(login smart-http-owner "$OWNER_JAR")" "Smart Collection owner can authenticate"

LIBRARY_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     --dump-header /tmp/smart-library.headers     --output /tmp/smart-library.html     --write-out '%{http_code}'     "$BASE_URL/library?media_type=image&location_name=Vienna&tag=Wedding&rating_min=4.5&orientation=landscape")"
expect_status 200 "$LIBRARY_STATUS" "authenticated owner can browse filtered library"
grep -i -F 'x-robots-tag: noindex, nofollow' /tmp/smart-library.headers >/dev/null
grep -i -E '^cache-control:.*no-store' /tmp/smart-library.headers >/dev/null
grep -F 'Alice Photo' /tmp/smart-library.html >/dev/null
grep -F 'Save as Smart Collection' /tmp/smart-library.html >/dev/null

FILTER_TOKEN="$(form_token /tmp/smart-library.html '/library/smart-collections/from-filter')"

NO_CSRF_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     --output /tmp/smart-no-csrf.html     --write-out '%{http_code}'     --data-urlencode 'title=Facet Smart'     --data-urlencode 'media_type=image'     --data-urlencode 'location_name=Vienna'     --data-urlencode 'tag=Wedding'     --data-urlencode 'rating_min=4.5'     --data-urlencode 'orientation=landscape'     "$BASE_URL/library/smart-collections/from-filter")"
expect_status 403 "$NO_CSRF_STATUS" "Smart Collection mutation rejects missing CSRF"

CREATE_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     --output /tmp/smart-create.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$FILTER_TOKEN"     --data-urlencode 'title=Facet Smart'     --data-urlencode 'media_type=image'     --data-urlencode 'location_name=Vienna'     --data-urlencode 'tag=Wedding'     --data-urlencode 'rating_min=4.5'     --data-urlencode 'orientation=landscape'     "$BASE_URL/library/smart-collections/from-filter")"
expect_status 302 "$CREATE_STATUS" "compatible library filter can be saved as Smart Collection"

COLLECTION_ID="$(php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = $db->fetchOne(
    "SELECT id FROM collections
     WHERE owner_id = :owner
       AND title = 'Facet Smart'
       AND mode = 'smart'
       AND deleted_at IS NULL",
    ['owner' => '66666666-6666-4666-8666-666666666661'],
);
if ($id === false) {
    throw new RuntimeException('Saved Smart Collection missing.');
}
echo $id;
$db->close();
PHP
)"

DETAIL_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     --dump-header /tmp/smart-detail.headers     --output /tmp/smart-detail.html     --write-out '%{http_code}'     "$BASE_URL/library/smart-collections/$COLLECTION_ID")"
expect_status 200 "$DETAIL_STATUS" "owner can open Smart Collection management page"
grep -F 'Facet Smart' /tmp/smart-detail.html >/dev/null
grep -F 'Alice Photo' /tmp/smart-detail.html >/dev/null
grep -F 'Dynamic · not materialized' /tmp/smart-detail.html >/dev/null

SMART_COLLECTION_ID="$COLLECTION_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$raw = $db->fetchOne(
    'SELECT smart_rule FROM collections WHERE id = :id',
    ['id' => (string) getenv('SMART_COLLECTION_ID')],
);
$rule = \Mediarama\Collection\Domain\SmartCollectionRule::fromArray(
    json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR),
)->payload();
$expected = [
    ['field' => 'media_type', 'operator' => 'eq', 'value' => 'image'],
    ['field' => 'location_name', 'operator' => 'contains', 'value' => 'Vienna'],
    ['field' => 'tag', 'operator' => 'has_tag', 'value' => 'Wedding'],
    ['field' => 'rating_average', 'operator' => 'gte', 'value' => 4.5],
    ['field' => 'orientation', 'operator' => 'eq', 'value' => 'landscape'],
];
if (($rule['op'] ?? null) !== 'and' || ($rule['rules'] ?? null) !== $expected) {
    throw new RuntimeException('Full Smart V1 facet filter did not round-trip losslessly.');
}
echo "OK full Smart V1 facets round-trip through HTTP save-filter boundary\n";

$count = (int) $db->fetchOne(
    'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
    ['id' => (string) getenv('SMART_COLLECTION_ID')],
);
if ($count !== 0) {
    throw new RuntimeException('Smart management materialized collection_media rows.');
}
$db->close();
echo "OK saved Smart filter remains dynamically resolved\n";
PHP

EDIT_TOKEN="$(first_token /tmp/smart-detail.html)"
EDIT_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     --output /tmp/smart-edit.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$EDIT_TOKEN"     --data-urlencode 'title=Nikon Smart'     --data-urlencode 'group_operator=and'     --data-urlencode 'field[]=camera_model'     --data-urlencode 'operator[]=contains'     --data-urlencode 'value[]=Nikon'     --data-urlencode 'secondary[]='     "$BASE_URL/library/smart-collections/$COLLECTION_ID")"
expect_status 302 "$EDIT_STATUS" "owner can edit Smart title and validated rule"

SMART_COLLECTION_ID="$COLLECTION_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$row = $db->fetchAssociative(
    'SELECT title, smart_rule FROM collections WHERE id = :id',
    ['id' => (string) getenv('SMART_COLLECTION_ID')],
);
if ($row === false) {
    throw new RuntimeException('Edited Smart Collection is missing.');
}
$rule = json_decode((string) $row['smart_rule'], true, flags: JSON_THROW_ON_ERROR);
if (
    ($row['title'] ?? null) !== 'Nikon Smart'
    || ($rule['rules'][0]['field'] ?? null) !== 'camera_model'
    || ($rule['rules'][0]['operator'] ?? null) !== 'contains'
) {
    throw new RuntimeException('Smart edit did not persist normalized rule.');
}
$db->close();
echo "OK Smart edit persisted through management boundary\n";
PHP

OTHER_JAR=/tmp/smart-other.cookies
expect_status 302 "$(login smart-http-other "$OTHER_JAR")" "second active user can authenticate"
OTHER_STATUS="$(curl --silent --show-error     --cookie "$OTHER_JAR"     --cookie-jar "$OTHER_JAR"     --output /tmp/smart-other-detail.html     --write-out '%{http_code}'     "$BASE_URL/library/smart-collections/$COLLECTION_ID")"
expect_status 404 "$OTHER_STATUS" "non-owner cannot read another user's Smart management page"

curl --fail --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     "$BASE_URL/library/smart-collections/$COLLECTION_ID"     -o /tmp/smart-delete-page.html
DELETE_TOKEN="$(form_token /tmp/smart-delete-page.html "/library/smart-collections/$COLLECTION_ID/delete")"

DELETE_STATUS="$(curl --silent --show-error     --cookie "$OWNER_JAR"     --cookie-jar "$OWNER_JAR"     --output /tmp/smart-delete.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$DELETE_TOKEN"     "$BASE_URL/library/smart-collections/$COLLECTION_ID/delete")"
expect_status 302 "$DELETE_STATUS" "owner can soft-delete Smart Collection"

SMART_COLLECTION_ID="$COLLECTION_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$deleted = $db->fetchOne(
    'SELECT deleted_at FROM collections WHERE id = :id',
    ['id' => (string) getenv('SMART_COLLECTION_ID')],
);
if ($deleted === false || $deleted === null) {
    throw new RuntimeException('Smart Collection was not soft-deleted.');
}
$db->close();
echo "OK Smart Collection delete uses soft-delete lifecycle\n";
PHP

echo "Authenticated Smart Collection management and save-filter HTTP checks passed."
