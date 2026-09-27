#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"

BASE_URL="http://127.0.0.1:8083"
OWNER_ID="88888888-8888-4888-8888-888888888888"
OTHER_ID="99999999-9999-4999-8999-999999999999"
PASSWORD="mediarama-success-ci-password"
OWNER_JAR=/tmp/upload-success-owner.cookies
OTHER_JAR=/tmp/upload-success-other.cookies
SERVER_PID=""

rm -f "$OWNER_JAR" "$OTHER_JAR"

cleanup_fixture() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi

    OWNER_ID="$OWNER_ID" OTHER_ID="$OTHER_ID" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP' || true
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$owner = (string) getenv('OWNER_ID');
$other = (string) getenv('OTHER_ID');
$ids = [];

foreach ($db->fetchFirstColumn(
    'SELECT id FROM upload_sessions WHERE user_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
) as $id) {
    $ids[(string) $id] = true;
}
foreach ($db->fetchFirstColumn(
    'SELECT id FROM media_assets WHERE owner_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
) as $id) {
    $ids[(string) $id] = true;
}

foreach (array_keys($ids) as $id) {
    $db->executeStatement(
        "DELETE FROM messenger_messages WHERE queue_name IN ('async', 'failed') AND body LIKE :needle",
        ['needle' => '%'.$id.'%'],
    );
    $db->delete('media_derivatives', ['media_id' => $id]);
    $db->delete('collection_media', ['media_id' => $id]);
    $db->executeStatement(
        'DELETE FROM upload_finalizations WHERE upload_session_id = :id OR media_id = :id',
        ['id' => $id],
    );
    $db->delete('media_assets', ['id' => $id]);
    $db->delete('upload_sessions', ['id' => $id]);

    $root = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/');
    foreach ([
        $root.'/chunks/'.$id,
        $root.'/temporary/'.$id,
        $root.'/originals/'.$id,
        $root.'/derivatives/'.$id,
    ] as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}

$db->delete('users', ['id' => $owner]);
$db->delete('users', ['id' => $other]);
PHP

    rm -f         /tmp/upload-success.jpg         /tmp/upload-success-*.json         /tmp/upload-success-*.html         /tmp/upload-success-*.headers         /tmp/mediarama-upload-success-http.log
}
trap cleanup_fixture EXIT

cleanup_fixture
trap cleanup_fixture EXIT

OWNER_ID="$OWNER_ID" OTHER_ID="$OTHER_ID" PASSWORD="$PASSWORD" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$password = password_hash((string) getenv('PASSWORD'), PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($password)) {
    throw new RuntimeException('Could not create upload success test password hash.');
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);
foreach ([
    [(string) getenv('OWNER_ID'), 'success-ci-owner'],
    [(string) getenv('OTHER_ID'), 'success-ci-other'],
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
PHP

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8083 -t public public/index.php >/tmp/mediarama-upload-success-http.log 2>&1 &
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
        cat /tmp/mediarama-upload-success-http.log || true
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
    token="$(login_csrf "$jar" "/tmp/upload-success-$username.html")"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output "/tmp/upload-success-$username-response.html"         --write-out '%{http_code}'         --data-urlencode "_username=$username"         --data-urlencode "_password=$PASSWORD"         --data-urlencode "_csrf_token=$token"         "$BASE_URL/login"
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
    local filename="$3"
    local size="$4"
    local mime="$5"
    local output="$6"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --header 'Content-Type: application/json'         --data "{\"filename\":\"$filename\",\"size\":$size,\"mime\":\"$mime\"}"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads"
}

put_chunk() {
    local jar="$1"
    local csrf="$2"
    local upload_id="$3"
    local checksum="$4"
    local source="$5"
    local output="$6"

    curl --silent --show-error         --request PUT         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --header 'Upload-Offset: 0'         --header "Upload-Checksum-SHA256: $checksum"         --data-binary "@$source"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id/chunks/0"
}

post_action() {
    local jar="$1"
    local csrf="$2"
    local upload_id="$3"
    local action="$4"
    local output="$5"

    curl --silent --show-error         --request POST         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id/$action"
}

get_upload() {
    local jar="$1"
    local upload_id="$2"
    local output="$3"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id"
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

convert -size 64x48 xc:white /tmp/upload-success.jpg
exiftool -overwrite_original     '-IFD0:Make=HTTP Fixture Camera Co'     '-IFD0:Model=HTTP FixtureCam 1'     '-ExifIFD:DateTimeOriginal=2025:04:05 12:34:56'     '-ExifIFD:ISO=200'     '-XMP-dc:Title=HTTP Success Fixture'     '-XMP-dc:Description=Successful authenticated ingestion fixture'     '-XMP-dc:Creator=HTTP Fixture Creator'     '-XMP-dc:Subject=integration'     '-XMP-dc:Subject+=upload'     /tmp/upload-success.jpg >/tmp/upload-success-exiftool.txt

SOURCE_SIZE="$(wc -c < /tmp/upload-success.jpg | tr -d ' ')"
SOURCE_SHA="$(sha256sum /tmp/upload-success.jpg | awk '{print $1}')"

expect_status 302 "$(login success-ci-owner "$OWNER_JAR")" "success owner login succeeds"
expect_status 302 "$(login success-ci-other "$OTHER_JAR")" "success observer login succeeds"

OWNER_CSRF="$(api_csrf "$OWNER_JAR" /tmp/upload-success-owner-csrf.json)"
OTHER_CSRF="$(api_csrf "$OTHER_JAR" /tmp/upload-success-other-csrf.json)"

expect_status 201 "$(create_upload "$OWNER_JAR" "$OWNER_CSRF" upload-success.jpg "$SOURCE_SIZE" image/jpeg /tmp/upload-success-create.json)" "authenticated upload session created"
UPLOAD_ID="$(extract_id /tmp/upload-success-create.json)"

expect_status 404 "$(get_upload "$OTHER_JAR" "$UPLOAD_ID" /tmp/upload-success-other-status.json)" "other user cannot inspect owner upload"
expect_status 404 "$(put_chunk "$OTHER_JAR" "$OTHER_CSRF" "$UPLOAD_ID" "$SOURCE_SHA" /tmp/upload-success.jpg /tmp/upload-success-other-chunk.json)" "other user cannot mutate owner chunks"

expect_status 202 "$(put_chunk "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" "$SOURCE_SHA" /tmp/upload-success.jpg /tmp/upload-success-chunk.json)" "owner chunk accepted"
expect_status 200 "$(get_upload "$OWNER_JAR" "$UPLOAD_ID" /tmp/upload-success-status-uploading.json)" "upload status available during acquisition"

SOURCE_SIZE="$SOURCE_SIZE" SOURCE_SHA="$SOURCE_SHA" php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  $chunks = $data["chunks"] ?? [];
  if (($data["status"] ?? null) !== "uploading"
      || ($data["expected_size"] ?? null) !== (int) getenv("SOURCE_SIZE")
      || !array_key_exists("failure", $data)
      || $data["failure"] !== null
      || count($chunks) !== 1
      || ($chunks[0]["index"] ?? null) !== 0
      || ($chunks[0]["offset"] ?? null) !== 0
      || ($chunks[0]["size"] ?? null) !== (int) getenv("SOURCE_SIZE")
      || ($chunks[0]["checksum_sha256"] ?? null) !== getenv("SOURCE_SHA")) {
      fwrite(STDERR, "Unexpected resumable upload state: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK status exposes the accepted resumable chunk".PHP_EOL;
' /tmp/upload-success-status-uploading.json

expect_status 200 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" complete /tmp/upload-success-complete.json)" "owner completes assembled upload"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["status"] ?? null) !== "uploaded") {
      fwrite(STDERR, "Unexpected complete response: ".json_encode($data).PHP_EOL);
      exit(1);
  }
' /tmp/upload-success-complete.json

test -f "$MEDIA_STORAGE_PATH/temporary/$UPLOAD_ID/source"
if [ -d "$MEDIA_STORAGE_PATH/chunks/$UPLOAD_ID" ]; then
    echo "Chunk directory survived successful completion."
    exit 1
fi

expect_status 202 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-success-finalize.json)" "owner finalizes valid media"
expect_status 202 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-success-finalize-repeat.json)" "repeated finalize is idempotent before processing"
expect_status 404 "$(post_action "$OTHER_JAR" "$OTHER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-success-other-finalize.json)" "other user cannot finalize owner upload"

UPLOAD_ID="$UPLOAD_ID" OWNER_ID="$OWNER_ID" SOURCE_SIZE="$SOURCE_SIZE" SOURCE_SHA="$SOURCE_SHA" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$id = (string) getenv('UPLOAD_ID');
$owner = (string) getenv('OWNER_ID');
$size = (int) getenv('SOURCE_SIZE');
$sha = (string) getenv('SOURCE_SHA');

$first = json_decode((string) file_get_contents('/tmp/upload-success-finalize.json'), true, flags: JSON_THROW_ON_ERROR);
$repeat = json_decode((string) file_get_contents('/tmp/upload-success-finalize-repeat.json'), true, flags: JSON_THROW_ON_ERROR);
if (($first['media_id'] ?? null) !== $id
    || ($repeat['media_id'] ?? null) !== $id
    || ($first['processing_state'] ?? null) !== 'pending'
    || ($repeat['processing_state'] ?? null) !== 'pending') {
    throw new RuntimeException('Finalize did not return the deterministic pending MediaAsset.');
}

$session = $db->fetchAssociative(
    'SELECT status, last_failure_code FROM upload_sessions WHERE id = :id',
    ['id' => $id],
);
if ($session === false || $session['status'] !== 'completed' || $session['last_failure_code'] !== null) {
    throw new RuntimeException('UploadSession was not completed cleanly.');
}

if ((int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id',
    ['id' => $id],
) !== 0) {
    throw new RuntimeException('Successful finalization left a quota reservation.');
}

$media = $db->fetchAssociative(
    'SELECT owner_id, storage_disk, storage_key, original_filename, byte_size, processing_state
     FROM media_assets WHERE id = :id',
    ['id' => $id],
);
if ($media === false
    || $media['owner_id'] !== $owner
    || $media['storage_disk'] !== 'media'
    || $media['storage_key'] !== 'originals/'.$id.'/source'
    || $media['original_filename'] !== 'upload-success.jpg'
    || (int) $media['byte_size'] !== $size
    || $media['processing_state'] !== 'pending') {
    throw new RuntimeException('Pending MediaAsset does not match finalized upload.');
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
    throw new RuntimeException('Repeated finalization did not leave exactly one async processing message.');
}

$root = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/');
$permanent = $root.'/originals/'.$id.'/source';
if (!is_file($permanent)
    || filesize($permanent) !== $size
    || hash_file('sha256', $permanent) !== $sha) {
    throw new RuntimeException('Immutable original is missing or differs from uploaded bytes.');
}

if (is_file($root.'/temporary/'.$id.'/source') || is_dir($root.'/chunks/'.$id)) {
    throw new RuntimeException('Temporary upload state survived successful finalization.');
}

echo "OK finalization atomically converts the upload into one queued MediaAsset".PHP_EOL;
PHP

APP_ENV=prod APP_DEBUG=0 php bin/console messenger:consume async --limit=1 --time-limit=30 --no-interaction -vv

UPLOAD_ID="$UPLOAD_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('UPLOAD_ID');

$media = $db->fetchAssociative(
    'SELECT processing_state, title, description, creator, camera_make, camera_model,
            iso, captured_at, width, height, metadata_provenance
     FROM media_assets WHERE id = :id',
    ['id' => $id],
);
if ($media === false) {
    throw new RuntimeException('Processed MediaAsset is missing.');
}

if ($media['processing_state'] !== 'ready') {
    throw new RuntimeException('MediaAsset did not reach ready state.');
}
if ($media['title'] !== 'HTTP Success Fixture'
    || $media['description'] !== 'Successful authenticated ingestion fixture'
    || $media['creator'] !== 'HTTP Fixture Creator'
    || $media['camera_make'] !== 'HTTP Fixture Camera Co'
    || $media['camera_model'] !== 'HTTP FixtureCam 1'
    || (int) $media['iso'] !== 200
    || $media['captured_at'] === null
    || (int) $media['width'] !== 64
    || (int) $media['height'] !== 48) {
    throw new RuntimeException('Normalized embedded metadata/geometry was not persisted correctly: '.json_encode($media));
}

$provenance = json_decode((string) $media['metadata_provenance'], true, flags: JSON_THROW_ON_ERROR);
foreach (['title', 'description', 'creator', 'camera_make', 'camera_model', 'iso', 'captured_at'] as $field) {
    if (($provenance[$field] ?? null) !== 'embedded') {
        throw new RuntimeException('Unexpected metadata provenance for '.$field);
    }
}

$derivatives = $db->fetchAllAssociative(
    "SELECT profile, processing_version, storage_key, mime_type, byte_size, width, height
     FROM media_derivatives
     WHERE media_id = :id AND kind = 'image'
     ORDER BY profile",
    ['id' => $id],
);
if (count($derivatives) !== 3) {
    throw new RuntimeException('Expected exactly three image derivatives.');
}

$profiles = [];
foreach ($derivatives as $derivative) {
    $profile = (string) $derivative['profile'];
    $profiles[$profile] = true;
    if ((int) $derivative['processing_version'] !== 1
        || $derivative['storage_key'] !== 'derivatives/'.$id.'/v1/'.$profile.'.webp'
        || $derivative['mime_type'] !== 'image/webp'
        || (int) $derivative['byte_size'] <= 0
        || (int) $derivative['width'] <= 0
        || (int) $derivative['height'] <= 0) {
        throw new RuntimeException('Unexpected derivative row: '.json_encode($derivative));
    }
}
foreach (['large', 'preview', 'thumbnail'] as $profile) {
    if (!isset($profiles[$profile])) {
        throw new RuntimeException('Missing derivative profile '.$profile);
    }
}

if ((int) $db->fetchOne(
    "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'",
) !== 0) {
    throw new RuntimeException('Async queue was not drained after processing.');
}

echo "OK real Messenger processing persisted metadata, geometry and all image derivatives".PHP_EOL;
PHP

for profile in thumbnail preview large; do
    test -s "$MEDIA_STORAGE_PATH/derivatives/$UPLOAD_ID/v1/$profile.webp"
done

expect_status 200 "$(get_upload "$OWNER_JAR" "$UPLOAD_ID" /tmp/upload-success-status-completed.json)" "completed upload remains readable by owner"
expect_status 404 "$(get_upload "$OTHER_JAR" "$UPLOAD_ID" /tmp/upload-success-other-completed.json)" "completed upload remains private from other user"
expect_status 202 "$(post_action "$OWNER_JAR" "$OWNER_CSRF" "$UPLOAD_ID" finalize /tmp/upload-success-finalize-ready.json)" "repeated finalize remains stable after processing"

UPLOAD_ID="$UPLOAD_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$data = json_decode((string) file_get_contents('/tmp/upload-success-finalize-ready.json'), true, flags: JSON_THROW_ON_ERROR);
if (($data['media_id'] ?? null) !== getenv('UPLOAD_ID') || ($data['processing_state'] ?? null) !== 'ready') {
    throw new RuntimeException('Repeated finalize did not return the stable ready MediaAsset.');
}

$status = json_decode((string) file_get_contents('/tmp/upload-success-status-completed.json'), true, flags: JSON_THROW_ON_ERROR);
if (($status['status'] ?? null) !== 'completed'
    || !array_key_exists('failure', $status)
    || $status['failure'] !== null
    || ($status['chunks'] ?? null) !== []) {
    throw new RuntimeException('Completed status is not stable and clean.');
}

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
if ((int) $db->fetchOne(
    "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'",
) !== 0) {
    throw new RuntimeException('Repeated finalize enqueued duplicate processing.');
}

echo "OK completed finalize is idempotent and does not re-enqueue processing".PHP_EOL;
PHP

curl --fail --silent --show-error     -D /tmp/upload-success-public-search.headers     "$BASE_URL/api/media?q=HTTP%20Success%20Fixture"     -o /tmp/upload-success-public-search.json

grep -i -F "x-robots-tag: noindex, nofollow" /tmp/upload-success-public-search.headers
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["items"] ?? null) !== []) {
      fwrite(STDERR, "Uncollected library-root upload leaked into public search: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK uncollected upload is absent from public discovery".PHP_EOL;
' /tmp/upload-success-public-search.json

PUBLIC_DERIVATIVE_STATUS="$(curl --silent --output /tmp/upload-success-public-derivative --write-out '%{http_code}' "$BASE_URL/media/$UPLOAD_ID/derivatives/v1/thumbnail")"
expect_status 404 "$PUBLIC_DERIVATIVE_STATUS" "uncollected upload derivative is not publicly deliverable"

echo "Successful authenticated upload ingestion integration checks passed."
