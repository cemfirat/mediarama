#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"

BASE_URL="http://127.0.0.1:8082"
USER_ID="66666666-6666-4666-8666-666666666666"
OTHER_ID="77777777-7777-4777-8777-777777777777"
PASSWORD="mediarama-failure-ci-password"
USER_JAR=/tmp/upload-failure-user.cookies
OTHER_JAR=/tmp/upload-failure-other.cookies

rm -f "$USER_JAR" "$OTHER_JAR"
rm -rf "$MEDIA_STORAGE_PATH/chunks" "$MEDIA_STORAGE_PATH/temporary"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM users WHERE username LIKE 'failure-ci-%'");

$password = password_hash('mediarama-failure-ci-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($password)) {
    throw new RuntimeException('Could not create failure-lifecycle password hash.');
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);
foreach ([
    ['66666666-6666-4666-8666-666666666666', 'failure-ci-owner'],
    ['77777777-7777-4777-8777-777777777777', 'failure-ci-other'],
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

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8082 -t public public/index.php >/tmp/mediarama-upload-failure-http.log 2>&1 &
SERVER_PID=$!

cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true

    php <<'PHP' || true
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM users WHERE username LIKE 'failure-ci-%'");
PHP
}
trap cleanup EXIT

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
        cat /tmp/mediarama-upload-failure-http.log || true
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

post_upload_action() {
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

delete_upload() {
    local jar="$1"
    local csrf="$2"
    local upload_id="$3"
    local output="$4"

    curl --silent --show-error         --request DELETE         --cookie "$jar"         --cookie-jar "$jar"         --header "X-CSRF-Token: $csrf"         --output "$output"         --write-out '%{http_code}'         "$BASE_URL/api/uploads/$upload_id"
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

expect_status 302 "$(login failure-ci-owner "$USER_JAR")" "failure owner login succeeds"
expect_status 302 "$(login failure-ci-other "$OTHER_JAR")" "failure observer login succeeds"
USER_CSRF="$(api_csrf "$USER_JAR" /tmp/failure-user-csrf.json)"

# Build a real JPEG signature/container prefix that fileinfo recognizes, then
# truncate it before image data so ImageMagick must reject the structure.
convert -size 2x2 xc:white /tmp/failure-valid-source.jpg
head -c 64 /tmp/failure-valid-source.jpg > /tmp/failure-invalid.jpg
INVALID_SIZE="$(wc -c < /tmp/failure-invalid.jpg | tr -d ' ')"
INVALID_SHA="$(sha256sum /tmp/failure-invalid.jpg | awk '{print $1}')"
WRONG_SHA="$(printf '%064d' 0)"

expect_status 201 "$(create_upload "$USER_JAR" "$USER_CSRF" failure-invalid.jpg "$INVALID_SIZE" image/jpeg /tmp/failure-create.json)" "failure test upload session created"
FAILURE_ID="$(extract_id /tmp/failure-create.json)"

expect_status 422 "$(put_chunk "$USER_JAR" "$USER_CSRF" "$FAILURE_ID" "$WRONG_SHA" /tmp/failure-invalid.jpg /tmp/failure-bad-chunk.json)" "checksum mismatch is retryable client failure"

php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["error"] ?? null) !== "chunk_checksum_mismatch"
      || ($data["retryable"] ?? null) !== true
      || ($data["failure_stage"] ?? null) !== "acquisition") {
      fwrite(STDERR, "Unexpected checksum failure payload: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK checksum failure payload is sanitized and retryable".PHP_EOL;
' /tmp/failure-bad-chunk.json

expect_status 200 "$(get_upload "$USER_JAR" "$FAILURE_ID" /tmp/failure-status-retryable.json)" "owner can inspect retryable failure"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  $failure = $data["failure"] ?? null;
  if (($data["status"] ?? null) !== "uploading"
      || !is_array($failure)
      || ($failure["code"] ?? null) !== "chunk_checksum_mismatch"
      || ($failure["stage"] ?? null) !== "acquisition"
      || ($failure["retryable"] ?? null) !== true
      || !is_string($failure["failed_at"] ?? null)) {
      fwrite(STDERR, "Retryable failure state is incorrect: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK retryable failure is durably observable".PHP_EOL;
' /tmp/failure-status-retryable.json

expect_status 404 "$(get_upload "$OTHER_JAR" "$FAILURE_ID" /tmp/failure-other-status.json)" "other user cannot inspect failure details"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["error"] ?? null) !== "upload_not_found" || array_key_exists("failure", $data)) {
      fwrite(STDERR, "Cross-user response leaked upload failure details: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK inaccessible upload is concealed as not found".PHP_EOL;
' /tmp/failure-other-status.json

expect_status 202 "$(put_chunk "$USER_JAR" "$USER_CSRF" "$FAILURE_ID" "$INVALID_SHA" /tmp/failure-invalid.jpg /tmp/failure-good-chunk.json)" "successful chunk retry is accepted"
expect_status 200 "$(get_upload "$USER_JAR" "$FAILURE_ID" /tmp/failure-status-cleared.json)" "status is readable after successful retry"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (!array_key_exists("failure", $data) || $data["failure"] !== null) {
      fwrite(STDERR, "Successful retry did not clear stale failure: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK successful retry clears failure metadata".PHP_EOL;
' /tmp/failure-status-cleared.json

expect_status 200 "$(post_upload_action "$USER_JAR" "$USER_CSRF" "$FAILURE_ID" complete /tmp/failure-complete.json)" "assembled malformed upload reaches uploaded state"
expect_status 409 "$(post_upload_action "$USER_JAR" "$USER_CSRF" "$FAILURE_ID" complete /tmp/failure-complete-again.json)" "repeated complete returns stable state conflict"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["error"] ?? null) !== "upload_state_conflict"
      || ($data["retryable"] ?? null) !== false) {
      fwrite(STDERR, "Unexpected repeated-complete payload: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK repeated complete is classified as a state conflict".PHP_EOL;
' /tmp/failure-complete-again.json

expect_status 422 "$(post_upload_action "$USER_JAR" "$USER_CSRF" "$FAILURE_ID" finalize /tmp/failure-finalize.json)" "decoder rejection is a stable terminal client failure"

php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($data["error"] ?? null) !== "invalid_media"
      || ($data["retryable"] ?? null) !== false
      || ($data["failure_stage"] ?? null) !== "finalization") {
      fwrite(STDERR, "Unexpected decoder rejection payload: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK decoder rejection uses stable public error contract".PHP_EOL;
' /tmp/failure-finalize.json

expect_status 200 "$(get_upload "$USER_JAR" "$FAILURE_ID" /tmp/failure-status-terminal.json)" "terminal failure remains queryable by owner"
php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  $failure = $data["failure"] ?? null;
  if (($data["status"] ?? null) !== "failed"
      || !is_array($failure)
      || ($failure["code"] ?? null) !== "invalid_media"
      || ($failure["stage"] ?? null) !== "finalization"
      || ($failure["retryable"] ?? null) !== false) {
      fwrite(STDERR, "Terminal failure state is incorrect: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK terminal failure is durably observable".PHP_EOL;
' /tmp/failure-status-terminal.json

FAILURE_ID="$FAILURE_ID" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('FAILURE_ID');

$row = $db->fetchAssociative(
    'SELECT status, last_failure_code, last_failure_stage, last_failure_retryable FROM upload_sessions WHERE id = :id',
    ['id' => $id],
);
if ($row === false
    || $row['status'] !== 'failed'
    || $row['last_failure_code'] !== 'invalid_media'
    || $row['last_failure_stage'] !== 'finalization'
    || filter_var($row['last_failure_retryable'], FILTER_VALIDATE_BOOLEAN) !== false) {
    throw new RuntimeException('Terminal upload failure was not persisted correctly.');
}

$reservations = (int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id',
    ['id' => $id],
);
if ($reservations !== 0) {
    throw new RuntimeException('Terminal upload failure did not release quota reservation.');
}

$temporary = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/').'/temporary/'.$id.'/source';
if (!is_file($temporary)) {
    throw new RuntimeException('Terminal failure should remain inspectable/abandonable until cleanup.');
}

echo "OK terminal failure releases quota without deleting observable session".PHP_EOL;
PHP

expect_status 204 "$(delete_upload "$USER_JAR" "$USER_CSRF" "$FAILURE_ID" /tmp/failure-abandon.json)" "failed upload can be explicitly abandoned"
expect_status 404 "$(get_upload "$USER_JAR" "$FAILURE_ID" /tmp/failure-after-abandon.json)" "abandoned upload no longer exists"

FAILURE_ID="$FAILURE_ID" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('FAILURE_ID');

if ((int) $db->fetchOne('SELECT COUNT(*) FROM upload_sessions WHERE id = :id', ['id' => $id]) !== 0) {
    throw new RuntimeException('Abandoned session still exists.');
}
if ((int) $db->fetchOne('SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id', ['id' => $id]) !== 0) {
    throw new RuntimeException('Abandoned reservation still exists.');
}
$temporary = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/').'/temporary/'.$id.'/source';
if (is_file($temporary)) {
    throw new RuntimeException('Abandon did not remove temporary upload object.');
}

echo "OK abandon removes session, reservation and temporary file".PHP_EOL;
PHP

# Simulate the crash window after finalization claim but before promotion.
printf 'test' > /tmp/failure-finalizing.bin
expect_status 201 "$(create_upload "$USER_JAR" "$USER_CSRF" finalizing.bin 4 application/octet-stream /tmp/finalizing-create.json)" "recoverable finalizing session created"
FINALIZING_ID="$(extract_id /tmp/finalizing-create.json)"

FINALIZING_ID="$FINALIZING_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->update('upload_sessions', ['status' => 'finalizing'], ['id' => getenv('FINALIZING_ID')]);
PHP

expect_status 503 "$(post_upload_action "$USER_JAR" "$USER_CSRF" "$FINALIZING_ID" finalize /tmp/finalizing-failure.json)" "missing finalization source remains retryable"
expect_status 200 "$(get_upload "$USER_JAR" "$FINALIZING_ID" /tmp/finalizing-status.json)" "recoverable finalizing status remains observable"

php -r '
  $data = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  $failure = $data["failure"] ?? null;
  if (($data["status"] ?? null) !== "finalizing"
      || !is_array($failure)
      || ($failure["code"] ?? null) !== "upload_temporarily_unavailable"
      || ($failure["stage"] ?? null) !== "finalization"
      || ($failure["retryable"] ?? null) !== true) {
      fwrite(STDERR, "Recoverable finalizing state became terminal: ".json_encode($data).PHP_EOL);
      exit(1);
  }
  echo "OK finalizing interruption remains retryable and non-terminal".PHP_EOL;
' /tmp/finalizing-status.json

expect_status 409 "$(delete_upload "$USER_JAR" "$USER_CSRF" "$FINALIZING_ID" /tmp/finalizing-abandon.json)" "finalizing session cannot be abandoned across recovery boundary"

FINALIZING_ID="$FINALIZING_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->delete('upload_sessions', ['id' => getenv('FINALIZING_ID')]);
PHP

# Expiry cleanup stays idempotent and releases quota through the FK cascade.
printf 'test' > /tmp/failure-expired.bin
EXPIRED_SHA="$(sha256sum /tmp/failure-expired.bin | awk '{print $1}')"
expect_status 201 "$(create_upload "$USER_JAR" "$USER_CSRF" expired.bin 4 application/octet-stream /tmp/expired-create.json)" "expiry cleanup session created"
EXPIRED_ID="$(extract_id /tmp/expired-create.json)"
expect_status 202 "$(put_chunk "$USER_JAR" "$USER_CSRF" "$EXPIRED_ID" "$EXPIRED_SHA" /tmp/failure-expired.bin /tmp/expired-chunk.json)" "expiry cleanup has real chunk data"

EXPIRED_ID="$EXPIRED_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->update(
    'upload_sessions',
    ['expires_at' => (new DateTimeImmutable('-1 day'))->format(DATE_ATOM)],
    ['id' => getenv('EXPIRED_ID')],
);
PHP

php bin/console mediarama:uploads:cleanup >/tmp/failure-cleanup-first.txt
php bin/console mediarama:uploads:cleanup >/tmp/failure-cleanup-second.txt

EXPIRED_ID="$EXPIRED_ID" MEDIA_STORAGE_PATH="$MEDIA_STORAGE_PATH" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('EXPIRED_ID');

if ((int) $db->fetchOne('SELECT COUNT(*) FROM upload_sessions WHERE id = :id', ['id' => $id]) !== 0) {
    throw new RuntimeException('Expired upload session survived cleanup.');
}
if ((int) $db->fetchOne('SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id', ['id' => $id]) !== 0) {
    throw new RuntimeException('Expired upload reservation survived cleanup.');
}
$chunkDir = rtrim((string) getenv('MEDIA_STORAGE_PATH'), '/').'/chunks/'.$id;
if (is_dir($chunkDir)) {
    throw new RuntimeException('Expired upload chunks survived cleanup.');
}

echo "OK repeated expiry cleanup leaves no session, reservation or chunks".PHP_EOL;
PHP

echo "Upload failure lifecycle integration checks passed."
