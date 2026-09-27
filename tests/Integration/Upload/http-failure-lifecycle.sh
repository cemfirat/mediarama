#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIA_STORAGE_PATH:?MEDIA_STORAGE_PATH must be set}"
: "${IMAGEMAGICK_BINARY:?IMAGEMAGICK_BINARY must be set}"

BASE_URL="http://127.0.0.1:8082"
USER_A_ID="77777777-7777-4777-8777-777777777777"
USER_B_ID="88888888-8888-4888-8888-888888888888"
PASSWORD="upload-failure-ci-password"
JAR_A="/tmp/upload-failure-a.cookies"
JAR_B="/tmp/upload-failure-b.cookies"
WORK="/tmp/mediarama-upload-failure-http"

rm -rf "$WORK"
mkdir -p "$WORK"
rm -f "$JAR_A" "$JAR_B"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM upload_sessions WHERE user_id IN (
    '77777777-7777-4777-8777-777777777777',
    '88888888-8888-4888-8888-888888888888'
)");
$db->executeStatement("DELETE FROM users WHERE username LIKE 'upload-failure-ci-%'");

$password = password_hash('upload-failure-ci-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($password)) {
    throw new RuntimeException('Could not create upload failure CI password hash.');
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);
foreach ([
    ['77777777-7777-4777-8777-777777777777', 'upload-failure-ci-owner'],
    ['88888888-8888-4888-8888-888888888888', 'upload-failure-ci-peer'],
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
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
    if curl --fail --silent "$BASE_URL/login" >/dev/null 2>&1; then
        break
    fi
    sleep 0.2
done

if ! curl --fail --silent --show-error "$BASE_URL/login" >/dev/null; then
    cat /tmp/mediarama-upload-failure-http.log || true
    exit 1
fi

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

csrf_token() {
    local jar="$1"
    local output="$2"

    curl --fail --silent --show-error \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        "$BASE_URL/login" \
        -o "$output"

    php -r '
      $html = (string) file_get_contents($argv[1]);
      if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
          fwrite(STDERR, "CSRF token missing from login form.\n");
          exit(1);
      }
      echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$output"
}

login() {
    local username="$1"
    local jar="$2"
    local page="$3"
    local token
    token="$(csrf_token "$jar" "$page")"

    curl --silent --show-error \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output "$WORK/login-post.html" \
        --write-out '%{http_code}' \
        --data-urlencode "_username=$username" \
        --data-urlencode "_password=$PASSWORD" \
        --data-urlencode "_csrf_token=$token" \
        "$BASE_URL/login"
}

api_csrf_token() {
    local jar="$1"
    local output="$2"

    curl --fail --silent --show-error \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        "$BASE_URL/api/auth/csrf" \
        -o "$output"

    php -r '
      $decoded = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      $token = $decoded["upload_token"] ?? null;
      if (!is_string($token) || $token === "") {
          fwrite(STDERR, "Upload CSRF token missing.\n");
          exit(1);
      }
      echo $token;
    ' "$output"
}

create_upload() {
    local jar="$1"
    local csrf="$2"
    local filename="$3"
    local size="$4"
    local mime="$5"
    local output="$6"

    curl --silent --show-error \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output "$output" \
        --write-out '%{http_code}' \
        --header 'Content-Type: application/json' \
        --header "X-CSRF-Token: $csrf" \
        --data "{\"filename\":\"$filename\",\"size\":$size,\"mime\":\"$mime\"}" \
        "$BASE_URL/api/uploads"
}

json_id() {
    php -r '
      $decoded = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      $id = $decoded["id"] ?? null;
      if (!is_string($id) || $id === "") {
          fwrite(STDERR, "Upload response has no id.\n");
          exit(1);
      }
      echo $id;
    ' "$1"
}

put_chunk() {
    local jar="$1"
    local csrf="$2"
    local id="$3"
    local index="$4"
    local offset="$5"
    local file="$6"
    local checksum="$7"
    local output="$8"
    local size
    size="$(wc -c < "$file" | tr -d ' ')"

    curl --silent --show-error \
        --request PUT \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output "$output" \
        --write-out '%{http_code}' \
        --header "X-CSRF-Token: $csrf" \
        --header "Content-Length: $size" \
        --header "Upload-Offset: $offset" \
        --header "Upload-Checksum-SHA256: $checksum" \
        --data-binary "@$file" \
        "$BASE_URL/api/uploads/$id/chunks/$index"
}

post_action() {
    local jar="$1"
    local csrf="$2"
    local id="$3"
    local action="$4"
    local output="$5"

    curl --silent --show-error \
        --request POST \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output "$output" \
        --write-out '%{http_code}' \
        --header "X-CSRF-Token: $csrf" \
        "$BASE_URL/api/uploads/$id/$action"
}

delete_upload() {
    local jar="$1"
    local csrf="$2"
    local id="$3"
    local output="$4"

    curl --silent --show-error \
        --request DELETE \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output "$output" \
        --write-out '%{http_code}' \
        --header "X-CSRF-Token: $csrf" \
        "$BASE_URL/api/uploads/$id"
}

get_status() {
    local jar="$1"
    local id="$2"
    local output="$3"

    curl --silent --show-error \
        --cookie "$jar" \
        --cookie-jar "$jar" \
        --output "$output" \
        --write-out '%{http_code}' \
        "$BASE_URL/api/uploads/$id"
}

assert_problem() {
    local file="$1"
    local code="$2"
    local retryable="$3"
    local stage="$4"

    php -r '
      $decoded = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      $expectedRetryable = $argv[3] === "true";
      if (($decoded["error"] ?? null) !== $argv[2]) {
          fwrite(STDERR, "Unexpected problem code: ".json_encode($decoded).PHP_EOL);
          exit(1);
      }
      if (($decoded["retryable"] ?? null) !== $expectedRetryable) {
          fwrite(STDERR, "Unexpected retryable flag: ".json_encode($decoded).PHP_EOL);
          exit(1);
      }
      if ($argv[4] !== "-" && ($decoded["stage"] ?? null) !== $argv[4]) {
          fwrite(STDERR, "Unexpected problem stage: ".json_encode($decoded).PHP_EOL);
          exit(1);
      }
      foreach (["trace", "file", "path", "sql"] as $forbidden) {
          if (array_key_exists($forbidden, $decoded)) {
              fwrite(STDERR, "Problem response leaked forbidden field: ".$forbidden.PHP_EOL);
              exit(1);
          }
      }
    ' "$file" "$code" "$retryable" "$stage"
}

assert_status_failure() {
    local file="$1"
    local status="$2"
    local code="$3"
    local retryable="$4"
    local stage="$5"

    php -r '
      $decoded = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      if (($decoded["status"] ?? null) !== $argv[2]) {
          fwrite(STDERR, "Unexpected upload status: ".json_encode($decoded).PHP_EOL);
          exit(1);
      }
      $failure = $decoded["failure"] ?? null;
      if (!is_array($failure)) {
          fwrite(STDERR, "Expected persisted failure object.\n");
          exit(1);
      }
      if (($failure["code"] ?? null) !== $argv[3] || ($failure["stage"] ?? null) !== $argv[5]) {
          fwrite(STDERR, "Unexpected persisted failure: ".json_encode($failure).PHP_EOL);
          exit(1);
      }
      if (($failure["retryable"] ?? null) !== ($argv[4] === "true")) {
          fwrite(STDERR, "Unexpected persisted retryable flag.\n");
          exit(1);
      }
      if (!is_string($failure["at"] ?? null) || ($failure["at"] ?? "") === "") {
          fwrite(STDERR, "Persisted failure timestamp is missing.\n");
          exit(1);
      }
    ' "$file" "$status" "$code" "$retryable" "$stage"
}

assert_status_clear() {
    local file="$1"
    local status="$2"

    php -r '
      $decoded = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
      if (($decoded["status"] ?? null) !== $argv[2] || array_key_exists("failure", $decoded) && $decoded["failure"] !== null) {
          fwrite(STDERR, "Upload failure did not clear: ".json_encode($decoded).PHP_EOL);
          exit(1);
      }
    ' "$file" "$status"
}

expect_status 302 "$(login upload-failure-ci-owner "$JAR_A" "$WORK/login-a.html")" "owner login"
CSRF_A="$(api_csrf_token "$JAR_A" "$WORK/csrf-a.json")"

# 1. Chunk checksum mismatch is retryable and a successful resend clears it.
printf 'abcdef' > "$WORK/retry.bin"
RETRY_SIZE="$(wc -c < "$WORK/retry.bin" | tr -d ' ')"
expect_status 201 "$(create_upload "$JAR_A" "$CSRF_A" retry.bin "$RETRY_SIZE" application/octet-stream "$WORK/retry-create.json")" "retryable upload create"
RETRY_ID="$(json_id "$WORK/retry-create.json")"
WRONG_HASH="$(printf '%064d' 0)"
RIGHT_HASH="$(sha256sum "$WORK/retry.bin" | awk '{print $1}')"

expect_status 422 "$(put_chunk "$JAR_A" "$CSRF_A" "$RETRY_ID" 0 0 "$WORK/retry.bin" "$WRONG_HASH" "$WORK/retry-wrong.json")" "chunk checksum mismatch"
assert_problem "$WORK/retry-wrong.json" upload_chunk_checksum_mismatch true acquisition

expect_status 200 "$(get_status "$JAR_A" "$RETRY_ID" "$WORK/retry-status-failed.json")" "retryable failure status"
assert_status_failure "$WORK/retry-status-failed.json" uploading upload_chunk_checksum_mismatch true acquisition

expect_status 202 "$(put_chunk "$JAR_A" "$CSRF_A" "$RETRY_ID" 0 0 "$WORK/retry.bin" "$RIGHT_HASH" "$WORK/retry-correct.json")" "chunk checksum retry succeeds"
expect_status 200 "$(get_status "$JAR_A" "$RETRY_ID" "$WORK/retry-status-clear.json")" "retryable failure clears"
assert_status_clear "$WORK/retry-status-clear.json" uploading
expect_status 409 "$(delete_upload "$JAR_A" "$CSRF_A" "$RETRY_ID" "$WORK/retry-delete.json")" "active acquisition cannot be race-unsafely abandoned"
assert_problem "$WORK/retry-delete.json" upload_invalid_state false -

# 2. Incomplete assembly is retryable; sending the missing chunk clears it.
printf 'abc' > "$WORK/part-a.bin"
printf 'def' > "$WORK/part-b.bin"
expect_status 201 "$(create_upload "$JAR_A" "$CSRF_A" incomplete.bin 6 application/octet-stream "$WORK/incomplete-create.json")" "incomplete upload create"
INCOMPLETE_ID="$(json_id "$WORK/incomplete-create.json")"
HASH_A="$(sha256sum "$WORK/part-a.bin" | awk '{print $1}')"
HASH_B="$(sha256sum "$WORK/part-b.bin" | awk '{print $1}')"
expect_status 202 "$(put_chunk "$JAR_A" "$CSRF_A" "$INCOMPLETE_ID" 0 0 "$WORK/part-a.bin" "$HASH_A" "$WORK/incomplete-a.json")" "first incomplete chunk accepted"
expect_status 409 "$(post_action "$JAR_A" "$CSRF_A" "$INCOMPLETE_ID" complete "$WORK/incomplete-problem.json")" "incomplete assembly rejected"
assert_problem "$WORK/incomplete-problem.json" upload_chunks_incomplete true assembly
expect_status 200 "$(get_status "$JAR_A" "$INCOMPLETE_ID" "$WORK/incomplete-status.json")" "incomplete assembly status"
assert_status_failure "$WORK/incomplete-status.json" uploading upload_chunks_incomplete true assembly

expect_status 202 "$(put_chunk "$JAR_A" "$CSRF_A" "$INCOMPLETE_ID" 1 3 "$WORK/part-b.bin" "$HASH_B" "$WORK/incomplete-b.json")" "missing chunk accepted"
expect_status 200 "$(get_status "$JAR_A" "$INCOMPLETE_ID" "$WORK/incomplete-cleared.json")" "assembly failure clears after chunk retry"
assert_status_clear "$WORK/incomplete-cleared.json" uploading
expect_status 200 "$(post_action "$JAR_A" "$CSRF_A" "$INCOMPLETE_ID" complete "$WORK/incomplete-complete.json")" "completed retryable assembly"
expect_status 204 "$(delete_upload "$JAR_A" "$CSRF_A" "$INCOMPLETE_ID" "$WORK/incomplete-delete.json")" "uploaded session can be explicitly abandoned"
expect_status 404 "$(get_status "$JAR_A" "$INCOMPLETE_ID" "$WORK/incomplete-after-delete.json")" "abandoned uploaded session is gone"
assert_problem "$WORK/incomplete-after-delete.json" upload_not_found false -

INCOMPLETE_ID="$INCOMPLETE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('INCOMPLETE_ID');
if ((int) $db->fetchOne('SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id', ['id' => $id]) !== 0) {
    fwrite(STDERR, "Abandonment did not release uploaded-session quota.\n");
    exit(1);
}
PHP

# 3. MIME-looking but decoder-invalid image is terminal and releases quota.
"$IMAGEMAGICK_BINARY" -size 32x32 xc:white "$WORK/valid.png"
VALID_SIZE="$(wc -c < "$WORK/valid.png" | tr -d ' ')"
TRUNCATED_SIZE="$((VALID_SIZE / 2))"
head -c "$TRUNCATED_SIZE" "$WORK/valid.png" > "$WORK/truncated.png"
BAD_SIZE="$(wc -c < "$WORK/truncated.png" | tr -d ' ')"
BAD_HASH="$(sha256sum "$WORK/truncated.png" | awk '{print $1}')"

expect_status 201 "$(create_upload "$JAR_A" "$CSRF_A" broken.png "$BAD_SIZE" image/png "$WORK/bad-create.json")" "terminal media upload create"
BAD_ID="$(json_id "$WORK/bad-create.json")"
expect_status 202 "$(put_chunk "$JAR_A" "$CSRF_A" "$BAD_ID" 0 0 "$WORK/truncated.png" "$BAD_HASH" "$WORK/bad-chunk.json")" "terminal media chunk accepted"
expect_status 200 "$(post_action "$JAR_A" "$CSRF_A" "$BAD_ID" complete "$WORK/bad-complete.json")" "terminal media assembled"
expect_status 422 "$(post_action "$JAR_A" "$CSRF_A" "$BAD_ID" finalize "$WORK/bad-finalize.json")" "decoder-invalid media rejected"
assert_problem "$WORK/bad-finalize.json" upload_media_invalid false validation

expect_status 200 "$(get_status "$JAR_A" "$BAD_ID" "$WORK/bad-status.json")" "terminal failure status"
assert_status_failure "$WORK/bad-status.json" failed upload_media_invalid false validation

BAD_ID="$BAD_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = (string) getenv('BAD_ID');
$reservations = (int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id',
    ['id' => $id],
);
if ($reservations !== 0) {
    fwrite(STDERR, "Terminal failure did not release quota reservation.\n");
    exit(1);
}
PHP

expect_status 409 "$(post_action "$JAR_A" "$CSRF_A" "$BAD_ID" finalize "$WORK/bad-repeat.json")" "terminal finalize retry rejected"
assert_problem "$WORK/bad-repeat.json" upload_invalid_state false -

# 4. Another authenticated user receives the same not-found boundary and no failure details.
expect_status 302 "$(login upload-failure-ci-peer "$JAR_B" "$WORK/login-b.html")" "peer login"
expect_status 404 "$(get_status "$JAR_B" "$BAD_ID" "$WORK/peer-status.json")" "other user cannot inspect upload failure"
assert_problem "$WORK/peer-status.json" upload_not_found false -
if grep -q -F 'upload_media_invalid' "$WORK/peer-status.json"; then
    echo "Other user response leaked owner failure details."
    exit 1
fi

expect_status 204 "$(delete_upload "$JAR_A" "$CSRF_A" "$BAD_ID" "$WORK/bad-delete.json")" "terminal failed session can be abandoned"
expect_status 404 "$(get_status "$JAR_A" "$BAD_ID" "$WORK/bad-after-delete.json")" "abandoned failed session is gone"
assert_problem "$WORK/bad-after-delete.json" upload_not_found false -
if [ -e "$MEDIA_STORAGE_PATH/temporary/$BAD_ID/source" ] || [ -e "$MEDIA_STORAGE_PATH/originals/$BAD_ID/source" ]; then
    echo "Abandonment left upload media artifacts behind."
    exit 1
fi

php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM upload_sessions WHERE user_id IN (
    '77777777-7777-4777-8777-777777777777',
    '88888888-8888-4888-8888-888888888888'
)");
$db->executeStatement("DELETE FROM users WHERE username LIKE 'upload-failure-ci-%'");
PHP

echo "Observable upload failure HTTP lifecycle checks passed."
