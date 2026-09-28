#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"

BASE_URL="http://127.0.0.1:8097"
OWNER_ID="99999999-9999-4999-8999-999999999991"
OTHER_ID="99999999-9999-4999-8999-999999999992"
MEDIA_VISUAL_ID="99999999-9999-4999-8999-999999999993"
MEDIA_METADATA_ID="99999999-9999-4999-8999-999999999994"
MEDIA_OTHER_ID="99999999-9999-4999-8999-999999999995"
PASSWORD="mediarama-organization-ai-browser-ci"
SPY_PATH="/tmp/mediarama-organization-ai-browser-spy.json"
FAIL_PATH="/tmp/mediarama-organization-ai-browser-fail"
SERVER_PID=""

export MEDIARAMA_ORGANIZATION_AI_SPY_PATH="$SPY_PATH"
export MEDIARAMA_ORGANIZATION_AI_FAIL_PATH="$FAIL_PATH"

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    rm -f "$SPY_PATH" "$FAIL_PATH"

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

$owner = '99999999-9999-4999-8999-999999999991';
$other = '99999999-9999-4999-8999-999999999992';
$media = [
    '99999999-9999-4999-8999-999999999993',
    '99999999-9999-4999-8999-999999999994',
    '99999999-9999-4999-8999-999999999995',
];

$db->executeStatement(
    'DELETE FROM organization_ai_preflights WHERE requester_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
$db->executeStatement(
    'DELETE FROM organization_runs WHERE requester_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
$db->executeStatement(
    "DELETE FROM tags WHERE name = 'AI Browser Candidate'"
);
foreach ($media as $id) {
    $db->delete('media_assets', ['id' => $id]);
}
$db->delete('users', ['id' => $owner]);
$db->delete('users', ['id' => $other]);
$db->close();

$root = rtrim((string) getenv('MEDIA_STORAGE_PATH'), DIRECTORY_SEPARATOR);
@unlink($root.'/organization-ai-browser/preview.png');
@rmdir($root.'/organization-ai-browser');
PHP
}
trap cleanup EXIT

rm -f "$SPY_PATH" "$FAIL_PATH"

php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Uid\Uuid;

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$owner = Uuid::fromString('99999999-9999-4999-8999-999999999991');
$other = Uuid::fromString('99999999-9999-4999-8999-999999999992');
$visual = Uuid::fromString('99999999-9999-4999-8999-999999999993');
$metadata = Uuid::fromString('99999999-9999-4999-8999-999999999994');
$otherMedia = Uuid::fromString('99999999-9999-4999-8999-999999999995');
$password = password_hash(
    'mediarama-organization-ai-browser-ci',
    PASSWORD_DEFAULT,
);
$now = '2026-09-28T17:00:00+00:00';

foreach ([
    [$owner, 'organization-ai-browser-owner'],
    [$other, 'organization-ai-browser-other'],
] as [$id, $username]) {
    $db->insert('users', [
        'id' => $id->toRfc4122(),
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

foreach ([
    [$visual, $owner, 'AI Browser Visual', 'Owner Creator', 'Vienna'],
    [$metadata, $owner, 'AI Browser Metadata', 'Owner Creator', 'Graz'],
    [$otherMedia, $other, 'AI Browser Private Other', 'Other Creator', 'Secret Place'],
] as [$id, $ownerId, $title, $creator, $location]) {
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $ownerId->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'organization-ai-browser/'.$id->toRfc4122().'/source',
        'original_filename' => 'PRIVATE_ORIGINAL_FILENAME_SENTINEL_'.$id->toRfc4122().'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => $title,
        'description' => null,
        'captured_at' => '2026-09-28T12:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => json_encode([
            'PRIVATE_RAW_METADATA_SENTINEL' => 'must-not-leave-the-browser-boundary',
        ], JSON_THROW_ON_ERROR),
        'metadata_provenance' => json_encode([
            'PRIVATE_PROVENANCE_SENTINEL' => 'embedded',
        ], JSON_THROW_ON_ERROR),
        'creator' => $creator,
        'copyright' => null,
        'camera_make' => 'Fixture Camera',
        'camera_model' => 'Browser AI',
        'lens' => '35mm',
        'iso' => 100,
        'aperture' => null,
        'exposure_time' => null,
        'focal_length' => null,
        'latitude' => 48.123456,
        'longitude' => 16.654321,
        'location_name' => $location,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

$root = rtrim((string) getenv('MEDIA_STORAGE_PATH'), DIRECTORY_SEPARATOR);
$directory = $root.'/organization-ai-browser';
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create AI browser presentation directory.');
}

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zk1sAAAAASUVORK5CYII=',
    true,
);
if ($png === false) {
    throw new RuntimeException('Unable to build AI browser presentation fixture.');
}
file_put_contents($directory.'/preview.png', $png);

$db->insert('media_derivatives', [
    'id' => Uuid::v7()->toRfc4122(),
    'media_id' => $visual->toRfc4122(),
    'kind' => 'image',
    'profile' => 'preview',
    'processing_version' => 1,
    'storage_disk' => 'media',
    'storage_key' => 'organization-ai-browser/preview.png',
    'mime_type' => 'image/png',
    'byte_size' => strlen($png),
    'width' => 1,
    'height' => 1,
    'duration_ms' => null,
    'metadata' => '{}',
    'created_at' => $now,
    'updated_at' => $now,
]);

$db->close();
PHP

APP_ENV=test APP_DEBUG=0 php -S 127.0.0.1:8097 -t public public/index.php >/tmp/mediarama-organization-ai-browser-http.log 2>&1 &
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
        cat /tmp/mediarama-organization-ai-browser-http.log || true
        exit 1
    fi

    echo "OK $label"
}

login() {
    local username="$1"
    local jar="$2"
    local page="/tmp/organization-ai-login-$username.html"

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

    curl --silent --show-error \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output /tmp/organization-ai-login-post.html \
        --write-out '%{http_code}' \
        --data-urlencode "_username=$username" \
        --data-urlencode "_password=$PASSWORD" \
        --data-urlencode "_csrf_token=$token" \
        "$BASE_URL/login"
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

button_token() {
    local page="$1"
    local action_fragment="$2"

    php -r '
        $html = (string) file_get_contents($argv[1]);
        $action = preg_quote($argv[2], "~");
        if (!preg_match("~<button[^>]*formaction=\"[^\"]*".$action."[^\"]*\"[^>]*>~s", $html, $button)) {
            fwrite(STDERR, "Target button not found.\n");
            exit(1);
        }
        if (!preg_match("/value=\"([^\"]+)\"/", $button[0], $token)) {
            fwrite(STDERR, "Button CSRF token missing.\n");
            exit(1);
        }
        echo html_entity_decode($token[1], ENT_QUOTES | ENT_HTML5);
    ' "$page" "$action_fragment"
}

OWNER_JAR=/tmp/organization-ai-owner.cookies
expect_status 302 "$(login organization-ai-browser-owner "$OWNER_JAR")" "AI browser owner can authenticate"

LIBRARY_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --dump-header /tmp/organization-ai-library.headers \
    --output /tmp/organization-ai-library.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library")"
expect_status 200 "$LIBRARY_STATUS" "configured AI provider is available from authenticated Library"
grep -F 'Analyze selected' /tmp/organization-ai-library.html >/dev/null
grep -F 'AI-assisted analysis' /tmp/organization-ai-library.html >/dev/null
grep -F 'Browser Test AI' /tmp/organization-ai-library.html >/dev/null
grep -F 'browser-test-model' /tmp/organization-ai-library.html >/dev/null
! grep -F 'PRIVATE_RAW_METADATA_SENTINEL' /tmp/organization-ai-library.html >/dev/null
! grep -F '48.123456' /tmp/organization-ai-library.html >/dev/null

AI_TOKEN="$(button_token /tmp/organization-ai-library.html '/library/organization/ai/preflights')"

NO_CSRF_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-no-csrf.html \
    --write-out '%{http_code}' \
    --data-urlencode "media_ids[]=$MEDIA_VISUAL_ID" \
    --data-urlencode 'provider_slot=0' \
    --data-urlencode 'provider_keys[0]=browser-test' \
    --data-urlencode 'capabilities[0][]=text_reasoning' \
    --data-urlencode 'input_mode=metadata_only' \
    "$BASE_URL/library/organization/ai/preflights")"
expect_status 403 "$NO_CSRF_STATUS" "AI preflight preparation rejects missing CSRF"

PREFLIGHTS_BEFORE="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT COUNT(*) FROM organization_ai_preflights WHERE requester_id = ?", ["99999999-9999-4999-8999-999999999991"]);
')"

UNAUTHORIZED_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-unauthorized.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$AI_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_VISUAL_ID" \
    --data-urlencode "media_ids[]=$MEDIA_OTHER_ID" \
    --data-urlencode 'provider_slot=0' \
    --data-urlencode 'provider_keys[0]=browser-test' \
    --data-urlencode 'capabilities[0][]=text_reasoning' \
    --data-urlencode 'input_mode=metadata_only' \
    "$BASE_URL/library/organization/ai/preflights")"
expect_status 302 "$UNAUTHORIZED_STATUS" "inaccessible media cannot enter browser AI preflight"

UNSUPPORTED_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-unsupported.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$AI_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_VISUAL_ID" \
    --data-urlencode 'provider_slot=0' \
    --data-urlencode 'provider_keys[0]=browser-test' \
    --data-urlencode 'capabilities[0][]=image_understanding' \
    --data-urlencode 'input_mode=metadata_only' \
    "$BASE_URL/library/organization/ai/preflights")"
expect_status 302 "$UNSUPPORTED_STATUS" "image understanding without presentation mode fails closed"

PREFLIGHTS_AFTER_INVALID="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT COUNT(*) FROM organization_ai_preflights WHERE requester_id = ?", ["99999999-9999-4999-8999-999999999991"]);
')"
if [ "$PREFLIGHTS_AFTER_INVALID" != "$PREFLIGHTS_BEFORE" ]; then
    echo "FAIL invalid browser AI requests persisted preflight state"
    exit 1
fi
if [ -e "$SPY_PATH" ]; then
    echo "FAIL provider inference ran before a valid approved execution"
    exit 1
fi

PREPARE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --dump-header /tmp/organization-ai-prepare.headers \
    --output /tmp/organization-ai-prepare.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$AI_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_VISUAL_ID" \
    --data-urlencode "media_ids[]=$MEDIA_METADATA_ID" \
    --data-urlencode 'provider_slot=0' \
    --data-urlencode 'provider_keys[0]=browser-test' \
    --data-urlencode 'capabilities[0][]=text_reasoning' \
    --data-urlencode 'capabilities[0][]=image_understanding' \
    --data-urlencode 'input_mode=metadata_and_presentation' \
    --data-urlencode 'include_creator=1' \
    "$BASE_URL/library/organization/ai/preflights")"
expect_status 302 "$PREPARE_STATUS" "browser prepares persisted AI privacy/cost preflight"

PREFLIGHT_ID="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT id FROM organization_ai_preflights WHERE requester_id = ? ORDER BY created_at DESC LIMIT 1", ["99999999-9999-4999-8999-999999999991"]);
')"

PREFLIGHT_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --dump-header /tmp/organization-ai-preflight.headers \
    --output /tmp/organization-ai-preflight.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID?prepared=1")"
expect_status 200 "$PREFLIGHT_STATUS" "owner can review AI preflight before approval"
grep -i -F 'x-robots-tag: noindex, nofollow' /tmp/organization-ai-preflight.headers >/dev/null
grep -i -E '^cache-control:.*no-store' /tmp/organization-ai-preflight.headers >/dev/null
grep -F 'Browser Test AI' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'browser-test-model' /tmp/organization-ai-preflight.html >/dev/null
grep -F '1 of 2' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'Estimated test cost EUR 0.01 for 2 media items.' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'Creator' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'included by explicit opt-in' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'coarse_location_name' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'exact_gps' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'source_storage' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'raw_metadata' /tmp/organization-ai-preflight.html >/dev/null
grep -F 'Never sent' /tmp/organization-ai-preflight.html >/dev/null
! grep -F 'PRIVATE_ORIGINAL_FILENAME_SENTINEL' /tmp/organization-ai-preflight.html >/dev/null
! grep -F 'PRIVATE_RAW_METADATA_SENTINEL' /tmp/organization-ai-preflight.html >/dev/null
! grep -F 'PRIVATE_PROVENANCE_SENTINEL' /tmp/organization-ai-preflight.html >/dev/null
! grep -F '48.123456' /tmp/organization-ai-preflight.html >/dev/null
! grep -F '16.654321' /tmp/organization-ai-preflight.html >/dev/null

if [ -e "$SPY_PATH" ]; then
    echo "FAIL provider inference ran during preflight generation"
    exit 1
fi

NO_APPROVE_CSRF="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-no-approve-csrf.html \
    --write-out '%{http_code}' \
    --request POST \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/approve")"
expect_status 403 "$NO_APPROVE_CSRF" "AI preflight approval rejects missing CSRF"

APPROVE_TOKEN="$(form_token /tmp/organization-ai-preflight.html "/library/organization/ai/preflights/$PREFLIGHT_ID/approve")"
APPROVE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-approve.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$APPROVE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/approve")"
expect_status 302 "$APPROVE_STATUS" "owner explicitly approves persisted AI preflight"

if [ -e "$SPY_PATH" ]; then
    echo "FAIL provider inference ran during explicit approval"
    exit 1
fi

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID?approved=1" \
    -o /tmp/organization-ai-approved.html

NO_EXECUTE_CSRF="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-no-execute-csrf.html \
    --write-out '%{http_code}' \
    --request POST \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/execute")"
expect_status 403 "$NO_EXECUTE_CSRF" "AI provider execution rejects missing CSRF"

if [ -e "$SPY_PATH" ]; then
    echo "FAIL provider inference ran without execution CSRF"
    exit 1
fi

EXECUTE_TOKEN="$(form_token /tmp/organization-ai-approved.html "/library/organization/ai/preflights/$PREFLIGHT_ID/execute")"
EXECUTE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-execute.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$EXECUTE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/execute")"
expect_status 302 "$EXECUTE_STATUS" "approved browser preflight executes provider exactly once"

php -r '
$state = json_decode((string) file_get_contents(getenv("MEDIARAMA_ORGANIZATION_AI_SPY_PATH")), true, flags: JSON_THROW_ON_ERROR);
if (
    ($state["calls"] ?? null) !== 1
    || ($state["media_count"] ?? null) !== 2
    || ($state["presentation_requested"] ?? null) !== 2
    || ($state["presentation_available"] ?? null) !== 1
    || ($state["creator_present"] ?? null) !== 2
    || ($state["location_present"] ?? null) !== 0
) {
    fwrite(STDERR, "Unexpected sanitized provider spy state.\n");
    exit(1);
}
echo "OK provider receives only the explicitly approved browser scope\n";
'

RUN_ID="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT run_id FROM organization_ai_preflights WHERE id = ?", [$argv[1]]);
' "$PREFLIGHT_ID")"

RUN_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-run.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library/organization/runs/$RUN_ID")"
expect_status 200 "$RUN_STATUS" "successful AI execution redirects into normal proposal review"
grep -F 'Browser Test AI' /tmp/organization-ai-run.html >/dev/null
grep -F 'browser-test-model' /tmp/organization-ai-run.html >/dev/null
grep -F 'AI Browser Candidate' /tmp/organization-ai-run.html >/dev/null
grep -F 'Inference evidence from the approved test provider scope.' /tmp/organization-ai-run.html >/dev/null
grep -F 'pending review' /tmp/organization-ai-run.html >/dev/null

AI_RUN_ID="$RUN_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql'=>'pdo_pgsql','postgres'=>'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
if ((int) $db->fetchOne("SELECT COUNT(*) FROM tags WHERE name = 'AI Browser Candidate'") !== 0) {
    throw new RuntimeException('AI proposal mutated tags before human review.');
}
if ((int) $db->fetchOne("SELECT COUNT(*) FROM collections WHERE owner_id = ?", ['99999999-9999-4999-8999-999999999991']) !== 0) {
    throw new RuntimeException('AI proposal mutated Collections before human review.');
}
$statuses = $db->fetchFirstColumn(
    "SELECT p.status
     FROM organization_proposals p
     JOIN organization_runs r ON r.id = p.run_id
     WHERE r.id = ?",
    [(string) getenv('AI_RUN_ID')],
);
if ($statuses === [] || array_unique($statuses) !== ['pending_review']) {
    throw new RuntimeException('AI provider proposals did not remain pending human review.');
}
$db->close();
echo "OK successful AI execution remains proposal-only until human review\n";
PHP

# Changed authorization after approval must fail before provider inference.
curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library" -o /tmp/organization-ai-library-refresh.html
AI_TOKEN="$(button_token /tmp/organization-ai-library-refresh.html '/library/organization/ai/preflights')"

SCOPE_PREPARE="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-scope-prepare.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$AI_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_METADATA_ID" \
    --data-urlencode 'provider_slot=0' \
    --data-urlencode 'provider_keys[0]=browser-test' \
    --data-urlencode 'capabilities[0][]=text_reasoning' \
    --data-urlencode 'input_mode=metadata_only' \
    "$BASE_URL/library/organization/ai/preflights")"
expect_status 302 "$SCOPE_PREPARE" "authorization-change fixture preflight prepared"
SCOPE_PREFLIGHT_ID="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT id FROM organization_ai_preflights WHERE requester_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1", ["99999999-9999-4999-8999-999999999991","pending_approval"]);
')"
curl --fail --silent --show-error --cookie "$OWNER_JAR" "$BASE_URL/library/organization/ai/preflights/$SCOPE_PREFLIGHT_ID" -o /tmp/organization-ai-scope-preflight.html
SCOPE_APPROVE_TOKEN="$(form_token /tmp/organization-ai-scope-preflight.html "/library/organization/ai/preflights/$SCOPE_PREFLIGHT_ID/approve")"
curl --silent --show-error --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --data-urlencode "_csrf_token=$SCOPE_APPROVE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$SCOPE_PREFLIGHT_ID/approve" >/dev/null

php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
$db->executeStatement("UPDATE media_assets SET owner_id = ? WHERE id = ?", ["99999999-9999-4999-8999-999999999992","99999999-9999-4999-8999-999999999994"]);
'

curl --fail --silent --show-error --cookie "$OWNER_JAR" "$BASE_URL/library/organization/ai/preflights/$SCOPE_PREFLIGHT_ID?approved=1" -o /tmp/organization-ai-scope-approved.html
SCOPE_EXECUTE_TOKEN="$(form_token /tmp/organization-ai-scope-approved.html "/library/organization/ai/preflights/$SCOPE_PREFLIGHT_ID/execute")"
SCOPE_EXECUTE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-scope-execute.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$SCOPE_EXECUTE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$SCOPE_PREFLIGHT_ID/execute")"
expect_status 302 "$SCOPE_EXECUTE_STATUS" "changed authorization fails closed before provider inference"

php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
$status = $db->fetchAssociative("SELECT status, failure_code FROM organization_ai_preflights WHERE id = ?", [$argv[1]]);
if (($status["status"] ?? null) !== "failed" || ($status["failure_code"] ?? null) !== "scope_unavailable") {
    fwrite(STDERR, "Authorization change did not record a sanitized failed preflight.\n");
    exit(1);
}
$db->executeStatement("UPDATE media_assets SET owner_id = ? WHERE id = ?", ["99999999-9999-4999-8999-999999999991","99999999-9999-4999-8999-999999999994"]);
' "$SCOPE_PREFLIGHT_ID"

# Provider failure records only sanitized failure state and no partial run.
curl --fail --silent --show-error --cookie "$OWNER_JAR" "$BASE_URL/library" -o /tmp/organization-ai-library-failure.html
AI_TOKEN="$(button_token /tmp/organization-ai-library-failure.html '/library/organization/ai/preflights')"
FAIL_PREPARE="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-fail-prepare.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$AI_TOKEN" \
    --data-urlencode "media_ids[]=$MEDIA_VISUAL_ID" \
    --data-urlencode 'provider_slot=0' \
    --data-urlencode 'provider_keys[0]=browser-test' \
    --data-urlencode 'capabilities[0][]=text_reasoning' \
    --data-urlencode 'input_mode=metadata_only' \
    "$BASE_URL/library/organization/ai/preflights")"
expect_status 302 "$FAIL_PREPARE" "provider-failure preflight prepared"
FAIL_PREFLIGHT_ID="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT id FROM organization_ai_preflights WHERE requester_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1", ["99999999-9999-4999-8999-999999999991","pending_approval"]);
')"
curl --fail --silent --show-error --cookie "$OWNER_JAR" "$BASE_URL/library/organization/ai/preflights/$FAIL_PREFLIGHT_ID" -o /tmp/organization-ai-fail-preflight.html
FAIL_APPROVE_TOKEN="$(form_token /tmp/organization-ai-fail-preflight.html "/library/organization/ai/preflights/$FAIL_PREFLIGHT_ID/approve")"
curl --silent --show-error --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --data-urlencode "_csrf_token=$FAIL_APPROVE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$FAIL_PREFLIGHT_ID/approve" >/dev/null
curl --fail --silent --show-error --cookie "$OWNER_JAR" "$BASE_URL/library/organization/ai/preflights/$FAIL_PREFLIGHT_ID?approved=1" -o /tmp/organization-ai-fail-approved.html
FAIL_EXECUTE_TOKEN="$(form_token /tmp/organization-ai-fail-approved.html "/library/organization/ai/preflights/$FAIL_PREFLIGHT_ID/execute")"

RUNS_BEFORE_FAILURE="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT COUNT(*) FROM organization_runs WHERE requester_id = ?", ["99999999-9999-4999-8999-999999999991"]);
')"
touch "$FAIL_PATH"
FAIL_EXECUTE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-ai-fail-execute.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$FAIL_EXECUTE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$FAIL_PREFLIGHT_ID/execute")"
rm -f "$FAIL_PATH"
expect_status 302 "$FAIL_EXECUTE_STATUS" "provider failure returns to sanitized preflight state"

php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
$row = $db->fetchAssociative("SELECT status, failure_code FROM organization_ai_preflights WHERE id = ?", [$argv[1]]);
if (($row["status"] ?? null) !== "failed" || ($row["failure_code"] ?? null) !== "provider_failure") {
    fwrite(STDERR, "Provider failure state was not sanitized.\n");
    exit(1);
}
$runs = (int) $db->fetchOne("SELECT COUNT(*) FROM organization_runs WHERE requester_id = ?", ["99999999-9999-4999-8999-999999999991"]);
if ($runs !== (int) $argv[2]) {
    fwrite(STDERR, "Provider failure created a partial organization run.\n");
    exit(1);
}
' "$FAIL_PREFLIGHT_ID" "$RUNS_BEFORE_FAILURE"

DURABLE="$(php -r '
require "vendor/autoload.php";
$dsn = new Doctrine\DBAL\Tools\DsnParser(["postgresql"=>"pdo_pgsql","postgres"=>"pdo_pgsql"]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string)getenv("DATABASE_URL")));
echo $db->fetchOne("SELECT COALESCE(string_agg(row_to_json(p)::text, ?), ?) FROM organization_ai_preflights p WHERE requester_id = ?", [" ","","99999999-9999-4999-8999-999999999991"]);
')"
for forbidden in RAW_PROVIDER_RESPONSE_SENTINEL PROVIDER_CREDENTIAL_SENTINEL PRIVATE_ORIGINAL_FILENAME_SENTINEL PRIVATE_RAW_METADATA_SENTINEL PRIVATE_PROVENANCE_SENTINEL 48.123456 16.654321; do
    if printf '%s' "$DURABLE" | grep -F "$forbidden" >/dev/null; then
        echo "FAIL durable browser AI state leaked $forbidden"
        exit 1
    fi
done

echo "Authenticated Organization AI browser preflight/approval/execution checks passed."
