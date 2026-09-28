#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"

BASE_URL="http://127.0.0.1:8097"
OWNER_ID="99999999-9999-4999-8999-999999999981"
OTHER_ID="99999999-9999-4999-8999-999999999982"
MEDIA_ONE="99999999-9999-4999-8999-999999999991"
MEDIA_TWO="99999999-9999-4999-8999-999999999992"
MEDIA_OTHER="99999999-9999-4999-8999-999999999993"
CALL_LOG="/tmp/mediarama-ai-browser-provider-calls"
SERVER_PID=""
COOKIE_JAR="/tmp/organization-ai-browser.cookies"

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
$owner = '99999999-9999-4999-8999-999999999981';
$other = '99999999-9999-4999-8999-999999999982';
$db->executeStatement(
    'DELETE FROM organization_ai_preflights WHERE requester_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
$db->executeStatement(
    'DELETE FROM organization_runs WHERE requester_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
$db->executeStatement(
    'DELETE FROM collections WHERE owner_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
foreach ([
    '99999999-9999-4999-8999-999999999991',
    '99999999-9999-4999-8999-999999999992',
    '99999999-9999-4999-8999-999999999993',
] as $media) {
    $db->delete('media_assets', ['id' => $media]);
}
$db->delete('users', ['id' => $owner]);
$db->delete('users', ['id' => $other]);
$db->close();
PHP

    rm -rf "$MEDIA_STORAGE_PATH/ai-browser"
    rm -f "$CALL_LOG" "$COOKIE_JAR"
}
trap cleanup EXIT

cleanup
trap cleanup EXIT

mkdir -p "$MEDIA_STORAGE_PATH/ai-browser/$MEDIA_ONE"
mkdir -p "$MEDIA_STORAGE_PATH/ai-browser/$MEDIA_TWO"
printf '%s' 'SAFE_PRESENTATION_ONE' > "$MEDIA_STORAGE_PATH/ai-browser/$MEDIA_ONE/preview.webp"
printf '%s' 'SAFE_PRESENTATION_TWO' > "$MEDIA_STORAGE_PATH/ai-browser/$MEDIA_TWO/preview.webp"

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

$owner = Uuid::fromString('99999999-9999-4999-8999-999999999981');
$other = Uuid::fromString('99999999-9999-4999-8999-999999999982');
$now = '2026-09-28T17:00:00+00:00';

foreach ([
    [$owner, 'ai-browser-owner'],
    [$other, 'ai-browser-other'],
] as [$id, $username]) {
    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => $username,
        'email' => null,
        'password_hash' => null,
        'display_name' => $username,
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}

$fixtures = [
    ['99999999-9999-4999-8999-999999999991', $owner, 'AI Browser One'],
    ['99999999-9999-4999-8999-999999999992', $owner, 'AI Browser Two'],
    ['99999999-9999-4999-8999-999999999993', $other, 'AI Browser Other'],
];

foreach ($fixtures as [$idValue, $mediaOwner, $title]) {
    $id = Uuid::fromString($idValue);
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $mediaOwner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'PRIVATE_ORIGINAL/'.$id->toRfc4122().'/source',
        'original_filename' => 'PRIVATE_ORIGINAL_'.$id->toRfc4122().'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 100,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => $title,
        'description' => null,
        'captured_at' => '2026-09-20T12:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => json_encode([
            'PRIVATE_RAW_AI_BROWSER_SENTINEL' => 'never-send',
        ], JSON_THROW_ON_ERROR),
        'metadata_provenance' => json_encode([
            'PRIVATE_PROVENANCE_SENTINEL' => 'never-send',
        ], JSON_THROW_ON_ERROR),
        'creator' => 'PRIVATE CREATOR SENTINEL',
        'camera_make' => 'Fixture',
        'camera_model' => 'Fixture Camera',
        'lens' => '35mm',
        'iso' => 100,
        'aperture' => null,
        'exposure_time' => null,
        'focal_length' => null,
        'latitude' => 48.123456,
        'longitude' => 16.654321,
        'location_name' => 'PRIVATE LOCATION SENTINEL',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

foreach ([
    '99999999-9999-4999-8999-999999999991' => 'SAFE_PRESENTATION_ONE',
    '99999999-9999-4999-8999-999999999992' => 'SAFE_PRESENTATION_TWO',
] as $mediaId => $bytes) {
    $db->insert('media_derivatives', [
        'id' => Uuid::v7()->toRfc4122(),
        'media_id' => $mediaId,
        'kind' => 'image',
        'profile' => 'preview',
        'processing_version' => 1,
        'storage_disk' => 'media',
        'storage_key' => 'ai-browser/'.$mediaId.'/preview.webp',
        'mime_type' => 'image/webp',
        'byte_size' => strlen($bytes),
        'width' => 800,
        'height' => 600,
        'duration_ms' => null,
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

$db->close();
PHP

APP_ENV=test APP_DEBUG=0 php -S 127.0.0.1:8097 -t public public/index.php >/tmp/mediarama-organization-ai-browser-http.log 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
    if curl --silent --show-error --header "X-Mediarama-User: $OWNER_ID" "$BASE_URL/library" >/dev/null 2>&1; then
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

curl --fail --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    "$BASE_URL/library" \
    -o /tmp/ai-browser-library.html

grep -F 'AI-assisted analysis' /tmp/ai-browser-library.html >/dev/null
ANALYZE_TOKEN="$(form_token /tmp/ai-browser-library.html '/library/organization/analyze')"

NO_CSRF_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-no-csrf.html \
    --write-out '%{http_code}' \
    --data-urlencode 'analysis_mode=ai' \
    --data-urlencode "media_ids[]=$MEDIA_ONE" \
    "$BASE_URL/library/organization/analyze")"
expect_status 403 "$NO_CSRF_STATUS" "AI setup rejects missing CSRF"

FOREIGN_SCOPE_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-foreign.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$ANALYZE_TOKEN" \
    --data-urlencode 'analysis_mode=ai' \
    --data-urlencode "media_ids[]=$MEDIA_ONE" \
    --data-urlencode "media_ids[]=$MEDIA_OTHER" \
    "$BASE_URL/library/organization/analyze")"
expect_status 302 "$FOREIGN_SCOPE_STATUS" "AI setup fails closed for inaccessible selected media"

SETUP_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --dump-header /tmp/ai-browser-setup.headers \
    --output /tmp/ai-browser-setup.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$ANALYZE_TOKEN" \
    --data-urlencode 'analysis_mode=ai' \
    --data-urlencode "media_ids[]=$MEDIA_ONE" \
    --data-urlencode "media_ids[]=$MEDIA_TWO" \
    "$BASE_URL/library/organization/analyze")"
expect_status 200 "$SETUP_STATUS" "authorized selected media opens AI provider setup"

grep -i -F 'x-robots-tag: noindex, nofollow' /tmp/ai-browser-setup.headers >/dev/null
grep -i -E '^cache-control:.*no-store' /tmp/ai-browser-setup.headers >/dev/null
grep -F 'CI Visual Provider' /tmp/ai-browser-setup.html >/dev/null
grep -F 'ci-vision-1' /tmp/ai-browser-setup.html >/dev/null
grep -F 'Nothing is sent to a provider on this page.' /tmp/ai-browser-setup.html >/dev/null
grep -F 'Originals, source storage, raw metadata/provenance and exact GPS are excluded.' /tmp/ai-browser-setup.html >/dev/null
! grep -F 'PRIVATE_RAW_AI_BROWSER_SENTINEL' /tmp/ai-browser-setup.html >/dev/null
! grep -F 'PRIVATE_ORIGINAL_' /tmp/ai-browser-setup.html >/dev/null
! grep -F '48.123456' /tmp/ai-browser-setup.html >/dev/null
test ! -e "$CALL_LOG"

PREPARE_TOKEN="$(form_token /tmp/ai-browser-setup.html '/library/organization/ai/preflight')"

INVALID_VISUAL_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-invalid-visual.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$PREPARE_TOKEN" \
    --data-urlencode 'provider_key=browser-fixture' \
    --data-urlencode 'capabilities[]=image_understanding' \
    --data-urlencode 'input_mode=metadata_only' \
    --data-urlencode "media_ids[]=$MEDIA_ONE" \
    --data-urlencode "media_ids[]=$MEDIA_TWO" \
    "$BASE_URL/library/organization/ai/preflight")"
expect_status 400 "$INVALID_VISUAL_STATUS" "image understanding fails closed without presentation mode"
test ! -e "$CALL_LOG"

PREPARE_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --dump-header /tmp/ai-browser-prepare.headers \
    --output /tmp/ai-browser-prepare.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$PREPARE_TOKEN" \
    --data-urlencode 'provider_key=browser-fixture' \
    --data-urlencode 'capabilities[]=text_reasoning' \
    --data-urlencode 'capabilities[]=image_understanding' \
    --data-urlencode 'input_mode=metadata_and_presentation' \
    --data-urlencode "media_ids[]=$MEDIA_ONE" \
    --data-urlencode "media_ids[]=$MEDIA_TWO" \
    "$BASE_URL/library/organization/ai/preflight")"
expect_status 302 "$PREPARE_STATUS" "visual AI preflight is persisted without inference"

PREFLIGHT_LOCATION="$(awk 'BEGIN {IGNORECASE=1} /^location:/ {gsub("\r", ""); print $2}' /tmp/ai-browser-prepare.headers | tail -n 1)"
if [[ "$PREFLIGHT_LOCATION" != /library/organization/ai/preflights/* ]]; then
    echo "FAIL AI preflight redirect missing"
    cat /tmp/ai-browser-prepare.headers
    exit 1
fi
PREFLIGHT_ID="${PREFLIGHT_LOCATION##*/}"
test ! -e "$CALL_LOG"

curl --fail --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --dump-header /tmp/ai-browser-preflight.headers \
    "$BASE_URL$PREFLIGHT_LOCATION" \
    -o /tmp/ai-browser-preflight.html

grep -i -F 'x-robots-tag: noindex, nofollow' /tmp/ai-browser-preflight.headers >/dev/null
grep -i -E '^cache-control:.*no-store' /tmp/ai-browser-preflight.headers >/dev/null
grep -F 'MediaAssets' /tmp/ai-browser-preflight.html >/dev/null
grep -F '2 approved presentation derivatives' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'Originals' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'Never sent' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'CI estimate: 2 media / 2 presentation derivatives' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'exact_gps' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'original_filename' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'raw_metadata' /tmp/ai-browser-preflight.html >/dev/null
grep -F 'source_storage' /tmp/ai-browser-preflight.html >/dev/null
grep -F '>Excluded<' /tmp/ai-browser-preflight.html >/dev/null
! grep -F 'PRIVATE CREATOR SENTINEL' /tmp/ai-browser-preflight.html >/dev/null
! grep -F 'PRIVATE LOCATION SENTINEL' /tmp/ai-browser-preflight.html >/dev/null
test ! -e "$CALL_LOG"

NO_APPROVE_CSRF="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-no-approve-csrf.html \
    --write-out '%{http_code}' \
    --request POST \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/approve")"
expect_status 403 "$NO_APPROVE_CSRF" "AI preflight approval rejects missing CSRF"
test ! -e "$CALL_LOG"

APPROVE_TOKEN="$(form_token /tmp/ai-browser-preflight.html "/library/organization/ai/preflights/$PREFLIGHT_ID/approve")"
APPROVE_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-approve.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$APPROVE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/approve")"
expect_status 302 "$APPROVE_STATUS" "user can deliberately approve exact preflight scope"
test ! -e "$CALL_LOG"

curl --fail --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID?approved=1" \
    -o /tmp/ai-browser-approved.html
grep -F 'Provider inference still has not run.' /tmp/ai-browser-approved.html >/dev/null
test ! -e "$CALL_LOG"

EXECUTE_TOKEN="$(form_token /tmp/ai-browser-approved.html "/library/organization/ai/preflights/$PREFLIGHT_ID/execute")"
EXECUTE_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --dump-header /tmp/ai-browser-execute.headers \
    --output /tmp/ai-browser-execute.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$EXECUTE_TOKEN" \
    "$BASE_URL/library/organization/ai/preflights/$PREFLIGHT_ID/execute")"
expect_status 302 "$EXECUTE_STATUS" "approved visual provider analysis executes"

if [ "$(wc -l < "$CALL_LOG" | tr -d ' ')" != "1" ]; then
    echo "FAIL provider was not called exactly once after approval"
    cat "$CALL_LOG" || true
    exit 1
fi

RUN_LOCATION="$(awk 'BEGIN {IGNORECASE=1} /^location:/ {gsub("\r", ""); print $2}' /tmp/ai-browser-execute.headers | tail -n 1)"
if [[ "$RUN_LOCATION" != /library/organization/runs/* ]]; then
    echo "FAIL successful AI execution did not redirect to normal proposal review"
    cat /tmp/ai-browser-execute.headers
    exit 1
fi

curl --fail --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    "$BASE_URL$RUN_LOCATION" \
    -o /tmp/ai-browser-run.html

grep -F 'CI Visual Provider' /tmp/ai-browser-run.html >/dev/null
grep -F 'ci-vision-1' /tmp/ai-browser-run.html >/dev/null
grep -F 'AI visual review tag' /tmp/ai-browser-run.html >/dev/null
grep -F 'CI visual inference from approved presentation derivatives.' /tmp/ai-browser-run.html >/dev/null
grep -F '>inference<' /tmp/ai-browser-run.html >/dev/null
! grep -F 'PRIVATE_RAW_AI_BROWSER_SENTINEL' /tmp/ai-browser-run.html >/dev/null
! grep -F 'PRIVATE_ORIGINAL_' /tmp/ai-browser-run.html >/dev/null
! grep -F '48.123456' /tmp/ai-browser-run.html >/dev/null

PREFLIGHT_ID_ENV="$PREFLIGHT_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$row = $db->fetchAssociative(
    'SELECT status, run_id FROM organization_ai_preflights WHERE id = :id',
    ['id' => (string) getenv('PREFLIGHT_ID_ENV')],
);
if ($row === false || $row['status'] !== 'completed' || $row['run_id'] === null) {
    throw new RuntimeException('AI browser preflight did not complete into a run.');
}
$pending = (int) $db->fetchOne(
    "SELECT COUNT(*) FROM organization_proposals WHERE run_id = :run AND status = 'pending_review'",
    ['run' => (string) $row['run_id']],
);
$normalTag = (int) $db->fetchOne(
    "SELECT COUNT(*) FROM tags WHERE name = 'AI visual review tag'"
);
if ($pending !== 1 || $normalTag !== 0) {
    throw new RuntimeException('Provider proposal bypassed human review or mutated normal library state.');
}
$db->close();
echo "OK provider output remains pending proposal state\n";
PHP

# Build and approve a second identical preflight, then invalidate one approved
# presentation file before execution. The provider must not be called again.
SECOND_PREPARE_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --dump-header /tmp/ai-browser-second-prepare.headers \
    --output /tmp/ai-browser-second-prepare.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$PREPARE_TOKEN" \
    --data-urlencode 'provider_key=browser-fixture' \
    --data-urlencode 'capabilities[]=image_understanding' \
    --data-urlencode 'input_mode=metadata_and_presentation' \
    --data-urlencode "media_ids[]=$MEDIA_ONE" \
    --data-urlencode "media_ids[]=$MEDIA_TWO" \
    "$BASE_URL/library/organization/ai/preflight")"
expect_status 302 "$SECOND_PREPARE_STATUS" "second visual preflight can be prepared"

SECOND_LOCATION="$(awk 'BEGIN {IGNORECASE=1} /^location:/ {gsub("\r", ""); print $2}' /tmp/ai-browser-second-prepare.headers | tail -n 1)"
SECOND_ID="${SECOND_LOCATION##*/}"

curl --fail --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    "$BASE_URL$SECOND_LOCATION" \
    -o /tmp/ai-browser-second.html
SECOND_APPROVE="$(form_token /tmp/ai-browser-second.html "/library/organization/ai/preflights/$SECOND_ID/approve")"
expect_status 302 "$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-second-approved-post.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$SECOND_APPROVE" \
    "$BASE_URL/library/organization/ai/preflights/$SECOND_ID/approve")" \
    "second preflight approval succeeds"

curl --fail --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    "$BASE_URL/library/organization/ai/preflights/$SECOND_ID" \
    -o /tmp/ai-browser-second-approved.html
SECOND_EXECUTE="$(form_token /tmp/ai-browser-second-approved.html "/library/organization/ai/preflights/$SECOND_ID/execute")"

rm -f "$MEDIA_STORAGE_PATH/ai-browser/$MEDIA_TWO/preview.webp"

SECOND_EXECUTE_STATUS="$(curl --silent --show-error \
    --header "X-Mediarama-User: $OWNER_ID" \
    --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" \
    --output /tmp/ai-browser-second-execute.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$SECOND_EXECUTE" \
    "$BASE_URL/library/organization/ai/preflights/$SECOND_ID/execute")"
expect_status 302 "$SECOND_EXECUTE_STATUS" "changed approved presentation scope fails safely"

if [ "$(wc -l < "$CALL_LOG" | tr -d ' ')" != "1" ]; then
    echo "FAIL provider was called after approved presentation scope changed"
    cat "$CALL_LOG" || true
    exit 1
fi

SECOND_PREFLIGHT_ID="$SECOND_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$row = $db->fetchAssociative(
    'SELECT status, failure_code, run_id
     FROM organization_ai_preflights
     WHERE id = :id',
    ['id' => (string) getenv('SECOND_PREFLIGHT_ID')],
);
if (
    $row === false
    || $row['status'] !== 'failed'
    || $row['failure_code'] !== 'presentation_scope_changed'
    || $row['run_id'] !== null
) {
    throw new RuntimeException('Changed presentation scope did not fail closed before provider inference.');
}
$db->close();
echo "OK changed approved presentation scope failed before provider inference\n";
PHP

echo "Organization AI browser preflight/approval/execution checks passed."
