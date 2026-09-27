#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"

BASE_URL="http://127.0.0.1:8083"
OWNER_ID="88888888-8888-4888-8888-888888888888"
OTHER_ID="99999999-9999-4999-8999-999999999999"
PASSWORD="mediarama-upload-e2e-password"
OWNER_JAR=/tmp/upload-e2e-owner.cookies
OTHER_JAR=/tmp/upload-e2e-other.cookies
FIXTURE=/tmp/upload-e2e.jpg
CHUNK0=/tmp/upload-e2e.chunk0
CHUNK1=/tmp/upload-e2e.chunk1
UPLOAD_ID=""
SERVER_PID=""

rm -f "$OWNER_JAR" "$OTHER_JAR" "$FIXTURE" "$CHUNK0" "$CHUNK1"

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    UPLOAD_ID="$UPLOAD_ID" OWNER_ID="$OWNER_ID" OTHER_ID="$OTHER_ID" php <<'PHP' || true
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$uploadId = (string) getenv('UPLOAD_ID');
$ownerId = (string) getenv('OWNER_ID');
$otherId = (string) getenv('OTHER_ID');

if ($uploadId !== '') {
    $db->executeStatement(
        'DELETE FROM messenger_messages WHERE body LIKE :needle',
        ['needle' => '%'.$uploadId.'%'],
    );
    $db->executeStatement('DELETE FROM media_assets WHERE id = :id', ['id' => $uploadId]);
    $db->executeStatement('DELETE FROM upload_sessions WHERE id = :id', ['id' => $uploadId]);
}

$db->executeStatement(
    'DELETE FROM media_assets WHERE owner_id IN (:owner, :other)',
    ['owner' => $ownerId, 'other' => $otherId],
);
$db->executeStatement(
    'DELETE FROM upload_sessions WHERE user_id IN (:owner, :other)',
    ['owner' => $ownerId, 'other' => $otherId],
);
$db->executeStatement(
    'DELETE FROM users WHERE id IN (:owner, :other)',
    ['owner' => $ownerId, 'other' => $otherId],
);
PHP

    if [ -n "$UPLOAD_ID" ]; then
        rm -rf             "$MEDIA_STORAGE_PATH/chunks/$UPLOAD_ID"             "$MEDIA_STORAGE_PATH/temporary/$UPLOAD_ID"             "$MEDIA_STORAGE_PATH/originals/$UPLOAD_ID"             "$MEDIA_STORAGE_PATH/derivatives/$UPLOAD_ID"
    fi

    rm -f "$OWNER_JAR" "$OTHER_JAR" "$FIXTURE" "$CHUNK0" "$CHUNK1"
}
trap cleanup EXIT

OWNER_ID="$OWNER_ID" OTHER_ID="$OTHER_ID" PASSWORD="$PASSWORD" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$ownerId = (string) getenv('OWNER_ID');
$otherId = (string) getenv('OTHER_ID');

$db->executeStatement(
    'DELETE FROM media_assets WHERE owner_id IN (:owner, :other)',
    ['owner' => $ownerId, 'other' => $otherId],
);
$db->executeStatement(
    'DELETE FROM upload_sessions WHERE user_id IN (:owner, :other)',
    ['owner' => $ownerId, 'other' => $otherId],
);
$db->executeStatement(
    'DELETE FROM users WHERE id IN (:owner, :other)',
    ['owner' => $ownerId, 'other' => $otherId],
);

$password = password_hash((string) getenv('PASSWORD'), PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($password)) {
    throw new RuntimeException('Could not create upload E2E password hash.');
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);
foreach ([
    [$ownerId, 'upload-e2e-owner'],
    [$otherId, 'upload-e2e-other'],
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

if ((int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'") !== 0) {
    throw new RuntimeException('Async queue must be empty before successful HTTP upload E2E test.');
}
PHP

convert -size 96x64 xc:white "$FIXTURE"
exiftool -overwrite_original     '-IPTC:ObjectName=HTTP Upload Fixture'     '-IPTC:By-line=HTTP Upload Photographer'     '-IPTC:DateCreated=2026:09:27'     '-IPTC:Sub-location=Vienna E2E'     "$FIXTURE" >/dev/null

TOTAL_SIZE="$(stat -c '%s' "$FIXTURE")"
TOTAL_SHA="$(sha256sum "$FIXTURE" | awk '{print $1}')"
FIRST_SIZE="$((TOTAL_SIZE / 2))"

if [ "$FIRST_SIZE" -lt 1 ]; then
    echo "Fixture is unexpectedly empty."
    exit 1
fi

head -c "$FIRST_SIZE" "$FIXTURE" > "$CHUNK0"
tail -c "+$((FIRST_SIZE + 1))" "$FIXTURE" > "$CHUNK1"

SECOND_SIZE="$(stat -c '%s' "$CHUNK1")"
CHUNK0_SHA="$(sha256sum "$CHUNK0" | awk '{print $1}')"
CHUNK1_SHA="$(sha256sum "$CHUNK1" | awk '{print $1}')"

if [ "$((FIRST_SIZE + SECOND_SIZE))" -ne "$TOTAL_SIZE" ]; then
    echo "Chunk split does not reconstruct fixture size."
    exit 1
fi

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8083 -t public public/index.php >/tmp/mediarama-upload-e2e-http.log 2>&1 &
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
        cat /tmp/mediarama-upload-e2e-http.log || true
        exit 1
    fi

    echo "OK $label"
}

login_csrf() {
    local jar="$1"
    local page="$2"

    curl --fail --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         "$BASE_URL/login"         -o "$page"

    php -r '
      $html = (string) file_get_contents($argv[1]);
      if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
          fwrite(STDERR, "Login CSRF token missing.".PHP_EOL);
          exit(1);
      }
      echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$page"
}

login() {
    local username="$1"
    local jar="$2"
    local token
    token="$(login_csrf "$jar" "/tmp/$username-login.html")"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output "/tmp/$username-login-response.html"         --write-out '%{http_code}'         --data-urlencode "_username=$username"         --data-urlencode "_password=$PASSWORD"         --data-urlencode "_csrf_token=$token"         "$BASE_URL/login"
}

api_csrf() {
    local jar="$1"
    local output="$2"

    curl --fail --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         "$BASE_URL/api/auth/csrf"         -o "$output"

    php -r '
      $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      if (!isset($data["upload_token"]) || !is_string($data["upload_token"]) || $data["upload_token"] === "") {
          fwrite(STDERR, "Upload CSRF token missing.".PHP_EOL);
          exit(1);
      }
      echo $data["upload_token"];
    ' "$output"
}

create_upload() {
    local jar="$1"
    local csrf="$2"
    local output="$3"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --header 'Content-Type: application/json'         --data "{\"filename\":\"http-upload-e2e.jpg\",\"size\":$TOTAL_SIZE,\"mime\":\"image/jpeg\"}"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads"
}

put_chunk() {
    local jar="$1"
    local csrf="$2"
    local upload_id="$3"
    local index="$4"
    local offset="$5"
    local checksum="$6"
    local source="$7"
    local output="$8"

    curl --silent --show-error         --request PUT         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --header "Upload-Offset: $offset"         --header "Upload-Checksum-SHA256: $checksum"         --data-binary "@$source"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id/chunks/$index"
}

get_upload() {
    local jar="$1"
    local upload_id="$2"
    local output="$3"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id"
}

post_action() {
    local jar="$1"
    local csrf="$2"
    local upload_id="$3"
    local action="$4"
    local output="$5"

    curl --silent --show-error         --request POST         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id/$action"
}

extract_id() {
    php -r '
      $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      if (!isset($data["id"]) || !is_string($data["id"])) {
          fwrite(STDERR, "Upload id missing.".PHP_EOL);
          exit(1);
      }
      echo $data["id"];
    ' "$1"
}

expect_status 302 "$(login upload-e2e-owner "$OWNER_JAR")" "upload owner login succeeds"
expect_status 302 "$(login upload-e2e-other "$OTHER_JAR")" "second user login succeeds"
OWNER_CSRF="$(api_csrf "$OWNER_JAR" /tmp/upload-e2e-owner-csrf.json)"
OTHER_CSRF="$(api_csrf "$OTHER_JAR" /tmp/upload-e2e-other-csrf.json)"

expect_status 201 "$(create_upload "$OWNER_JAR" "$OWNER_CSRF" /tmp/upload-e2e-create.json)" "authenticated upload session is created"
UPLOAD_ID="$(extract_id /tmp/upload-e2e-create.json)"

UPLOAD_ID="$UPLOAD_ID" OWNER_ID="$OWNER_ID" TOTAL_SIZE="$TOTAL_SIZE" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('UPLOAD_ID');

$session = $db->fetchAssociative(
    'SELECT user_id, status, expected_size FROM upload_sessions WHERE id = :id',
    ['id' => $id],
);
if ($session === false
    || $session['user_id'] !== getenv('OWNER_ID')
    || $session['status'] !== 'created'
    || (int) $session['expected_size'] !== (int) getenv('TOTAL_SIZE')) {
    throw new RuntimeException('Created UploadSession does not match the authenticated request.');
}

$reservation = $db->fetchOne(
    'SELECT reserved_bytes FROM upload_quota_reservations WHERE upload_session_id = :id',
    ['id' => $id],
);
if ((int) $reservation !== (int) getenv('TOTAL_SIZE')) {
    throw new RuntimeException('Upload quota reservation is missing or has the wrong size.');
}

echo "OK create persists owner-bound session and quota reservation".PHP_EOL;
PHP

expect_status 202 "$(put_chunk "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" 0 0 "$CHUNK0_SHA" "$CHUNK0" /tmp/upload-e2e-chunk0.json)" "first chunk is accepted"
expect_status 200 "$(get_upload "$OWNER_JAR" "$UPLOAD_ID" /tmp/upload-e2e-status-one.json)" "resume status is available after first chunk"

FIRST_SIZE="$FIRST_SIZE" php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  $chunks = $data["chunks"] ?? null;
  if (($data["status"] ?? null) !== "uploading"
      || !array_key_exists("failure", $data)
      || $data["failure"] !== null
      || !is_array($chunks)
      || count($chunks) !== 1
      || ($chunks[0]["index"] ?? null) !== 0
      || ($chunks[0]["offset"] ?? null) !== 0
      || ($chunks[0]["size"] ?? null) !== (int) getenv("FIRST_SIZE")) {
      fwrite(STDERR, "Unexpected resume status: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK resume status exposes exactly the accepted first chunk".PHP_EOL;
' /tmp/upload-e2e-status-one.json

expect_status 404 "$(get_upload "$OTHER_JAR" "$UPLOAD_ID" /tmp/upload-e2e-other-status.json)" "other user cannot inspect owner upload"
expect_status 404 "$(post_action "$OTHER_JAR" "$OTHER_CSRF" "$UPLOAD_ID" complete /tmp/upload-e2e-other-complete.json)" "other user cannot mutate owner upload"

expect_status 202 "$(put_chunk "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" 1 "$FIRST_SIZE" "$CHUNK1_SHA" "$CHUNK1" /tmp/upload-e2e-chunk1.json)" "second chunk is accepted"
expect_status 200 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" complete /tmp/upload-e2e-complete.json)" "two chunks assemble successfully"
expect_status 200 "$(get_upload "$OWNER_JAR" "$UPLOAD_ID" /tmp/upload-e2e-status-uploaded.json)" "assembled upload status is readable"

php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["status"] ?? null) !== "uploaded"
      || !array_key_exists("failure", $data)
      || $data["failure"] !== null
      || !is_array($data["chunks"] ?? null)
      || count($data["chunks"]) !== 0) {
      fwrite(STDERR, "Unexpected assembled upload status: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK assembly removes chunks and leaves a clean uploaded session".PHP_EOL;
' /tmp/upload-e2e-status-uploaded.json

if [ ! -f "$MEDIA_STORAGE_PATH/temporary/$UPLOAD_ID/source" ]; then
    echo "Assembled temporary object is missing."
    exit 1
fi
if [ -d "$MEDIA_STORAGE_PATH/chunks/$UPLOAD_ID" ]; then
    echo "Chunk directory survived successful assembly."
    exit 1
fi

expect_status 202 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-e2e-finalize.json)" "valid image finalizes successfully"

UPLOAD_ID="$UPLOAD_ID" php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["media_id"] ?? null) !== getenv("UPLOAD_ID")
      || ($data["processing_state"] ?? null) !== "processing") {
      fwrite(STDERR, "Unexpected finalize response: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK finalize returns deterministic MediaAsset id in processing state".PHP_EOL;
' /tmp/upload-e2e-finalize.json

expect_status 202 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-e2e-finalize-repeat.json)" "repeat finalize is idempotent before worker processing"

UPLOAD_ID="$UPLOAD_ID" php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["media_id"] ?? null) !== getenv("UPLOAD_ID")
      || ($data["processing_state"] ?? null) !== "processing") {
      fwrite(STDERR, "Repeated finalize changed the result: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK repeated finalize returns the same MediaAsset".PHP_EOL;
' /tmp/upload-e2e-finalize-repeat.json

UPLOAD_ID="$UPLOAD_ID" OWNER_ID="$OWNER_ID" TOTAL_SIZE="$TOTAL_SIZE" TOTAL_SHA="$TOTAL_SHA" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('UPLOAD_ID');

$session = $db->fetchAssociative(
    'SELECT status, last_failure_code FROM upload_sessions WHERE id = :id',
    ['id' => $id],
);
if ($session === false || $session['status'] !== 'completed' || $session['last_failure_code'] !== null) {
    throw new RuntimeException('Finalized UploadSession is not completed/clean.');
}

if ((int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id',
    ['id' => $id],
) !== 0) {
    throw new RuntimeException('Quota reservation survived successful finalization.');
}

$asset = $db->fetchAssociative(
    'SELECT owner_id, storage_key, original_filename, mime_type, media_type, byte_size, checksum_sha256, processing_state, moderation_state
     FROM media_assets WHERE id = :id',
    ['id' => $id],
);
if ($asset === false
    || $asset['owner_id'] !== getenv('OWNER_ID')
    || $asset['storage_key'] !== 'originals/'.$id.'/source'
    || $asset['original_filename'] !== 'http-upload-e2e.jpg'
    || $asset['mime_type'] !== 'image/jpeg'
    || $asset['media_type'] !== 'image'
    || (int) $asset['byte_size'] !== (int) getenv('TOTAL_SIZE')
    || $asset['checksum_sha256'] !== getenv('TOTAL_SHA')
    || $asset['processing_state'] !== 'processing'
    || $asset['moderation_state'] !== 'draft') {
    throw new RuntimeException('Finalized MediaAsset does not match the uploaded original.');
}

if ((int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_finalizations WHERE upload_session_id = :id AND media_id = :id',
    ['id' => $id],
) !== 1) {
    throw new RuntimeException('Expected exactly one upload finalization mapping.');
}

if ((int) $db->fetchOne(
    "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'",
) !== 1) {
    throw new RuntimeException('Expected exactly one async processing message after repeated finalize.');
}

$root = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/');
$original = $root.'/originals/'.$id.'/source';
if (!is_file($original) || hash_file('sha256', $original) !== getenv('TOTAL_SHA')) {
    throw new RuntimeException('Immutable original is missing or checksum changed.');
}
if (is_file($root.'/temporary/'.$id.'/source') || is_dir($root.'/chunks/'.$id)) {
    throw new RuntimeException('Temporary or chunk data survived successful finalization.');
}

echo "OK finalization commits one original, one mapping, one queue message and releases quota".PHP_EOL;
PHP

expect_status 404 "$(post_action "$OTHER_JAR" "$OTHER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-e2e-other-finalize.json)" "other user cannot finalize completed owner upload"

APP_ENV=prod APP_DEBUG=0 php bin/console messenger:consume async --limit=1 --time-limit=30 --no-interaction -vv >/tmp/upload-e2e-consumer.log 2>&1

UPLOAD_ID="$UPLOAD_ID" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('UPLOAD_ID');

$asset = $db->fetchAssociative(
    'SELECT processing_state, moderation_state, width, height, title, creator, location_name, metadata_provenance
     FROM media_assets WHERE id = :id',
    ['id' => $id],
);
if ($asset === false
    || $asset['processing_state'] !== 'ready'
    || $asset['moderation_state'] !== 'draft'
    || (int) $asset['width'] !== 96
    || (int) $asset['height'] !== 64
    || $asset['title'] !== 'HTTP Upload Fixture'
    || $asset['creator'] !== 'HTTP Upload Photographer'
    || $asset['location_name'] !== 'Vienna E2E') {
    throw new RuntimeException('Background processing did not persist expected normalized metadata/geometry.');
}

$provenance = json_decode((string) $asset['metadata_provenance'], true, flags: JSON_THROW_ON_ERROR);
foreach (['title', 'creator', 'location_name'] as $field) {
    if (($provenance[$field] ?? null) !== 'embedded') {
        throw new RuntimeException('Expected embedded metadata provenance for '.$field.'.');
    }
}

$derivatives = $db->fetchAllAssociative(
    'SELECT profile, processing_version, storage_key, mime_type, byte_size, width, height, metadata
     FROM media_derivatives
     WHERE media_id = :id AND kind = :kind
     ORDER BY profile',
    ['id' => $id, 'kind' => 'image'],
);
if (count($derivatives) !== 3) {
    throw new RuntimeException('Expected exactly three image derivatives.');
}

$expectedProfiles = ['large', 'preview', 'thumbnail'];
$actualProfiles = array_column($derivatives, 'profile');
if ($actualProfiles !== $expectedProfiles) {
    throw new RuntimeException('Unexpected derivative profiles: '.json_encode($actualProfiles));
}

$root = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/');
$expectedQuality = [
    'thumbnail' => 82,
    'preview' => 84,
    'large' => 86,
];

foreach ($derivatives as $derivative) {
    if ((int) $derivative['processing_version'] !== 2
        || $derivative['mime_type'] !== 'image/webp'
        || (int) $derivative['byte_size'] < 1
        || (int) $derivative['width'] < 1
        || (int) $derivative['height'] < 1) {
        throw new RuntimeException('Derivative database state is invalid for '.$derivative['profile'].'.');
    }

    $metadata = json_decode((string) $derivative['metadata'], true, flags: JSON_THROW_ON_ERROR);
    if (($metadata['encoder'] ?? null) !== 'cwebp'
        || ($metadata['encoder_quality'] ?? null) !== ($expectedQuality[$derivative['profile']] ?? null)) {
        throw new RuntimeException('Derivative encoder provenance is invalid for '.$derivative['profile'].'.');
    }

    $path = $root.'/'.$derivative['storage_key'];
    if (!is_file($path) || filesize($path) !== (int) $derivative['byte_size']) {
        throw new RuntimeException('Derivative file is missing or differs from DB for '.$derivative['profile'].'.');
    }
}

if ((int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'") !== 0) {
    throw new RuntimeException('Async queue did not drain after successful processing.');
}
if ((int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'") !== 0) {
    throw new RuntimeException('Successful media processing produced a failed Messenger message.');
}

echo "OK real Messenger processing reaches ready with metadata and three persisted derivatives".PHP_EOL;
PHP

expect_status 202 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-e2e-finalize-ready.json)" "repeat finalize remains stable after worker processing"

UPLOAD_ID="$UPLOAD_ID" php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["media_id"] ?? null) !== getenv("UPLOAD_ID")
      || ($data["processing_state"] ?? null) !== "ready") {
      fwrite(STDERR, "Post-processing finalize is not stable: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK completed finalize returns the same ready MediaAsset".PHP_EOL;
' /tmp/upload-e2e-finalize-ready.json

UPLOAD_ID="$UPLOAD_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('UPLOAD_ID');

if ((int) $db->fetchOne(
    "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'",
) !== 0) {
    throw new RuntimeException('Idempotent finalize unexpectedly enqueued another processing message.');
}
if ((int) $db->fetchOne(
    'SELECT COUNT(*) FROM media_assets WHERE id = :id',
    ['id' => $id],
) !== 1) {
    throw new RuntimeException('Idempotent finalize duplicated the MediaAsset.');
}
if ((int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_finalizations WHERE upload_session_id = :id AND media_id = :id',
    ['id' => $id],
) !== 1) {
    throw new RuntimeException('Idempotent finalize duplicated the finalization mapping.');
}

echo "OK completed finalize remains exactly-once after background processing".PHP_EOL;
PHP

SEARCH_STATUS="$(curl --silent --show-error --output /tmp/upload-e2e-public-search.json --write-out '%{http_code}' "$BASE_URL/api/media?q=HTTP%20Upload%20Fixture")"
expect_status 200 "$SEARCH_STATUS" "public search remains available"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (count($data["items"] ?? []) !== 0) {
      fwrite(STDERR, "Uncollected draft upload leaked into public search.".PHP_EOL);
      exit(1);
  }
  echo "OK successful library-root upload is not public by accident".PHP_EOL;
' /tmp/upload-e2e-public-search.json

echo "Successful authenticated HTTP upload E2E checks passed."
