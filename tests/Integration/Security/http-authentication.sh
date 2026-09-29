#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"

BASE_URL="http://127.0.0.1:8081"
ACTIVE_ID="11111111-1111-4111-8111-111111111111"
PASSWORD="mediarama-ci-password"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM users WHERE username LIKE 'auth-ci-%'");

$password = password_hash('mediarama-ci-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($password)) {
    fwrite(STDERR, "Could not create CI password hash.\n");
    exit(1);
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);
$users = [
    ['11111111-1111-4111-8111-111111111111', 'auth-ci-active', 'active'],
    ['22222222-2222-4222-8222-222222222222', 'auth-ci-inactive', 'inactive'],
    ['33333333-3333-4333-8333-333333333333', 'auth-ci-reset', 'password_reset_required'],
    ['55555555-5555-4555-8555-555555555555', 'auth-ci-guard', 'active'],
];

foreach ($users as [$id, $username, $status]) {
    $db->insert('users', [
        'id' => $id,
        'username' => $username,
        'email' => null,
        'password_hash' => $password,
        'display_name' => $username,
        'status' => $status,
        'locale' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}
PHP

# This security test deliberately exercises anonymously readable public routes.
# New installations default to Private workspace, so make that public-route
# assumption explicit instead of depending on implicit installation state.
php bin/console mediarama:platform:deployment-profile public_publishing >/tmp/auth-publication-profile.txt

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8081 -t public public/index.php >/tmp/mediarama-auth-http.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
    if curl --fail --silent "$BASE_URL/login" >/dev/null 2>&1; then
        break
    fi
    sleep 0.2
done

if ! curl --fail --silent --show-error -D /tmp/auth-login-ready.headers "$BASE_URL/login" -o /tmp/auth-login-ready.html; then
    cat /tmp/mediarama-auth-http.log || true
    exit 1
fi
grep -i -F "x-robots-tag: noindex, nofollow" /tmp/auth-login-ready.headers
grep -i -F "no-store" /tmp/auth-login-ready.headers

expect_status() {
    local expected="$1"
    local actual="$2"
    local label="$3"

    if [ "$actual" != "$expected" ]; then
        echo "FAIL $label: expected HTTP $expected, got $actual"
        cat /tmp/mediarama-auth-http.log || true
        exit 1
    fi

    echo "OK $label"
}

upload_status() {
    local jar="${1:-}"
    local extra_header="${2:-}"
    local args=(
        --silent
        --show-error
        --output /tmp/auth-upload-body.json
        --write-out '%{http_code}'
        --header 'Content-Type: application/json'
        --data '{"filename":"auth-ci.jpg","size":4,"mime":"image/jpeg"}'
    )

    if [ -n "$jar" ]; then
        args+=(--cookie "$jar" --cookie-jar "$jar")
    fi
    if [ -n "$extra_header" ]; then
        args+=(--header "$extra_header")
    fi

    curl "${args[@]}" "$BASE_URL/api/uploads"
}

csrf_token() {
    local jar="$1"
    local page="$2"

    curl --fail --silent --show-error --cookie "$jar" --cookie-jar "$jar" "$BASE_URL/login" -o "$page"

    php -r '
      $html = (string) file_get_contents($argv[1]);
      if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
          fwrite(STDERR, "CSRF token missing from login form.\n");
          exit(1);
      }
      echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$page"
}

login_status() {
    local username="$1"
    local password="$2"
    local jar="$3"
    local token="$4"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output /tmp/auth-login-post-body.html         --write-out '%{http_code}'         --data-urlencode "_username=$username"         --data-urlencode "_password=$password"         --data-urlencode "_csrf_token=$token"         "$BASE_URL/login"
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
          fwrite(STDERR, "Upload CSRF token missing from authenticated response.\n");
          exit(1);
      }
      echo $token;
    ' "$output"
}

PUBLIC_COLLECTIONS_STATUS="$(curl --silent --show-error --output /tmp/auth-public-collections.html --write-out '%{http_code}' "$BASE_URL/collections")"
expect_status 200 "$PUBLIC_COLLECTIONS_STATUS" "public collections remain anonymously readable in prod"

PUBLIC_SEARCH_STATUS="$(curl --silent --show-error --output /tmp/auth-public-search.json --write-out '%{http_code}' "$BASE_URL/api/media?q=auth-ci")"
expect_status 200 "$PUBLIC_SEARCH_STATUS" "public media search remains anonymously readable in prod"

INVALID_PUBLIC_SEARCH_STATUS="$(curl --silent --show-error --output /tmp/auth-public-search-invalid.json --write-out '%{http_code}' "$BASE_URL/api/media?limit=not-a-number")"
expect_status 400 "$INVALID_PUBLIC_SEARCH_STATUS" "invalid public search pagination returns stable client error"
php -r '
  $decoded = json_decode((string) file_get_contents("/tmp/auth-public-search-invalid.json"), true, flags: JSON_THROW_ON_ERROR);
  if ($decoded !== ["error" => "invalid_search_query"]) {
      fwrite(STDERR, "Unexpected invalid public-search payload: ".json_encode($decoded).PHP_EOL);
      exit(1);
  }
  echo "OK invalid public search pagination is a stable client error".PHP_EOL;
'

ANON_STATUS="$(upload_status)"
expect_status 401 "$ANON_STATUS" "anonymous upload create is rejected"

PROTECTED_ID="44444444-4444-4444-8444-444444444444"
anonymous_endpoint_status() {
    local method="$1"
    local path="$2"

    curl --silent --show-error \
        --request "$method" \
        --output /tmp/auth-protected-body.json \
        --write-out '%{http_code}' \
        "$BASE_URL$path"
}

expect_status 401 "$(anonymous_endpoint_status GET "/api/uploads/$PROTECTED_ID")" "anonymous upload status is rejected"
expect_status 401 "$(anonymous_endpoint_status PUT "/api/uploads/$PROTECTED_ID/chunks/0")" "anonymous upload chunk is rejected"
expect_status 401 "$(anonymous_endpoint_status POST "/api/uploads/$PROTECTED_ID/complete")" "anonymous upload complete is rejected"
expect_status 401 "$(anonymous_endpoint_status POST "/api/uploads/$PROTECTED_ID/finalize")" "anonymous upload finalize is rejected"
expect_status 401 "$(anonymous_endpoint_status DELETE "/api/uploads/$PROTECTED_ID")" "anonymous upload abandon is rejected"

expect_status 401 "$(anonymous_endpoint_status GET "/api/auth/csrf")" "anonymous API CSRF token request is rejected"
expect_status 401 "$(anonymous_endpoint_status GET "/api/library/media")" "anonymous library media search is rejected"

FORGED_STATUS="$(upload_status "" "X-Mediarama-User: $ACTIVE_ID")"
expect_status 401 "$FORGED_STATUS" "forged development actor header is ignored in prod"


# Login throttling: prove successful auth resets the local username+IP limiter.
for round in 1 2; do
    THROTTLE_JAR="/tmp/auth-throttle-reset-$round.cookies"
    rm -f "$THROTTLE_JAR"

    for attempt in 1 2 3 4; do
        TOKEN="$(csrf_token "$THROTTLE_JAR" "/tmp/auth-throttle-reset-$round-$attempt.html")"
        expect_status 302 "$(login_status auth-ci-guard 'wrong-password' "$THROTTLE_JAR" "$TOKEN")" "throttle reset round $round failure $attempt redirects generically"
        expect_status 401 "$(upload_status "$THROTTLE_JAR")" "throttle reset round $round failure $attempt does not authenticate"
    done

    TOKEN="$(csrf_token "$THROTTLE_JAR" "/tmp/auth-throttle-reset-$round-success.html")"
    expect_status 302 "$(login_status auth-ci-guard "$PASSWORD" "$THROTTLE_JAR" "$TOKEN")" "throttle reset round $round correct password redirects"
    expect_status 403 "$(upload_status "$THROTTLE_JAR")" "throttle reset round $round success establishes authenticated session"
done

# Five failures exhaust the local username+IP limiter. Correct credentials must
# remain rejected until limiter state is reset.
THROTTLE_BLOCK_JAR=/tmp/auth-throttle-block.cookies
rm -f "$THROTTLE_BLOCK_JAR"

for attempt in 1 2 3 4 5; do
    TOKEN="$(csrf_token "$THROTTLE_BLOCK_JAR" "/tmp/auth-throttle-block-$attempt.html")"
    expect_status 302 "$(login_status auth-ci-guard 'wrong-password' "$THROTTLE_BLOCK_JAR" "$TOKEN")" "throttle blocking failure $attempt redirects generically"
    expect_status 401 "$(upload_status "$THROTTLE_BLOCK_JAR")" "throttle blocking failure $attempt does not authenticate"
done

TOKEN="$(csrf_token "$THROTTLE_BLOCK_JAR" /tmp/auth-throttle-block-correct.html)"
expect_status 302 "$(login_status auth-ci-guard "$PASSWORD" "$THROTTLE_BLOCK_JAR" "$TOKEN")" "throttled correct password uses generic redirect"
expect_status 401 "$(upload_status "$THROTTLE_BLOCK_JAR")" "correct credentials are blocked after five failed attempts"

curl --fail --silent --show-error \
    --cookie "$THROTTLE_BLOCK_JAR" \
    --cookie-jar "$THROTTLE_BLOCK_JAR" \
    "$BASE_URL/login" \
    -o /tmp/auth-throttle-visible-failure.html
php -r '
  $html = (string) file_get_contents($argv[1]);
  if (!preg_match("/<div class=\"uk-alert-danger\"[^>]*>\\s*<p>(.*?)<\\/p>/s", $html, $match)) {
      fwrite(STDERR, "Visible login failure alert is missing.".PHP_EOL);
      exit(1);
  }
  $message = trim(strip_tags($match[1]));
  $expected = "Sign-in failed. Check your credentials and account status.";
  if ($message !== $expected) {
      fwrite(STDERR, "Visible login failure is not generic: ".$message.PHP_EOL);
      exit(1);
  }
  echo "OK throttled login failure remains generic".PHP_EOL;
' /tmp/auth-throttle-visible-failure.html

APP_ENV=prod APP_DEBUG=0 php bin/console cache:pool:clear cache.rate_limiter --no-interaction

rm -f "$THROTTLE_BLOCK_JAR"
TOKEN="$(csrf_token "$THROTTLE_BLOCK_JAR" /tmp/auth-throttle-after-clear.html)"
expect_status 302 "$(login_status auth-ci-guard "$PASSWORD" "$THROTTLE_BLOCK_JAR" "$TOKEN")" "correct credentials redirect after limiter cache clear"
expect_status 403 "$(upload_status "$THROTTLE_BLOCK_JAR")" "correct credentials authenticate after limiter cache clear"

NO_CSRF_JAR=/tmp/auth-no-csrf.cookies
rm -f "$NO_CSRF_JAR"
curl --fail --silent --show-error --cookie-jar "$NO_CSRF_JAR" "$BASE_URL/login" -o /tmp/auth-no-csrf.html
NO_CSRF_STATUS="$(curl --silent --show-error     --cookie "$NO_CSRF_JAR"     --cookie-jar "$NO_CSRF_JAR"     --output /tmp/auth-no-csrf-post.html     --write-out '%{http_code}'     --data-urlencode '_username=auth-ci-active'     --data-urlencode "_password=$PASSWORD"     "$BASE_URL/login")"
expect_status 302 "$NO_CSRF_STATUS" "login without CSRF is rejected"
expect_status 401 "$(upload_status "$NO_CSRF_JAR")" "missing-CSRF login did not create an authenticated session"

ACTIVE_JAR=/tmp/auth-active.cookies
rm -f "$ACTIVE_JAR"
ACTIVE_TOKEN="$(csrf_token "$ACTIVE_JAR" /tmp/auth-active-login.html)"
expect_status 302 "$(login_status auth-ci-active "$PASSWORD" "$ACTIVE_JAR" "$ACTIVE_TOKEN")" "active user login redirects after success"

LIBRARY_STATUS="$(curl --silent --show-error \
    --cookie "$ACTIVE_JAR" \
    --cookie-jar "$ACTIVE_JAR" \
    --dump-header /tmp/auth-library-search.headers \
    --output /tmp/auth-library-search.json \
    --write-out '%{http_code}' \
    "$BASE_URL/api/library/media?q=auth-ci")"
expect_status 200 "$LIBRARY_STATUS" "authenticated active user can query library media search"
grep -i -F "x-robots-tag: noindex, nofollow" /tmp/auth-library-search.headers
grep -i -E '^cache-control:.*private' /tmp/auth-library-search.headers
grep -i -E '^cache-control:.*no-store' /tmp/auth-library-search.headers
php -r '
  $decoded = json_decode((string) file_get_contents("/tmp/auth-library-search.json"), true, flags: JSON_THROW_ON_ERROR);
  if (!isset($decoded["items"]) || !is_array($decoded["items"])) {
      fwrite(STDERR, "Authenticated library search response has no items array.".PHP_EOL);
      exit(1);
  }
  foreach ($decoded["items"] as $item) {
      foreach (["latitude", "longitude", "metadata", "storage_key"] as $forbidden) {
          if (array_key_exists($forbidden, $item)) {
              fwrite(STDERR, "Library search leaked forbidden field: ".$forbidden.PHP_EOL);
              exit(1);
          }
      }
  }
  echo "OK authenticated library search uses the private metadata DTO boundary".PHP_EOL;
'

INVALID_LIBRARY_STATUS="$(curl --silent --show-error     --cookie "$ACTIVE_JAR"     --cookie-jar "$ACTIVE_JAR"     --output /tmp/auth-library-invalid.json     --write-out '%{http_code}'     "$BASE_URL/api/library/media?iso_min=not-a-number")"
expect_status 400 "$INVALID_LIBRARY_STATUS" "invalid library search filter returns stable client error"
php -r '
  $decoded = json_decode((string) file_get_contents("/tmp/auth-library-invalid.json"), true, flags: JSON_THROW_ON_ERROR);
  if ($decoded !== ["error" => "invalid_search_query"]) {
      fwrite(STDERR, "Unexpected invalid library-search payload: ".json_encode($decoded).PHP_EOL);
      exit(1);
  }
  echo "OK invalid library search payload is stable".PHP_EOL;
'

INVALID_LIBRARY_PAGINATION_STATUS="$(curl --silent --show-error     --cookie "$ACTIVE_JAR"     --cookie-jar "$ACTIVE_JAR"     --output /tmp/auth-library-pagination-invalid.json     --write-out '%{http_code}'     "$BASE_URL/api/library/media?limit=not-a-number")"
expect_status 400 "$INVALID_LIBRARY_PAGINATION_STATUS" "invalid library pagination returns stable client error"

INVALID_LIBRARY_PAGE_STATUS="$(curl --silent --show-error     --cookie "$ACTIVE_JAR"     --cookie-jar "$ACTIVE_JAR"     --output /tmp/auth-library-page-invalid.html     --write-out '%{http_code}'     "$BASE_URL/library?limit=not-a-number")"
expect_status 400 "$INVALID_LIBRARY_PAGE_STATUS" "invalid library page pagination renders a client error instead of a server error"

UPLOAD_CSRF="$(api_csrf_token "$ACTIVE_JAR" /tmp/auth-upload-csrf.json)"
expect_status 403 "$(upload_status "$ACTIVE_JAR")" "authenticated upload without CSRF token is rejected"
expect_status 201 "$(upload_status "$ACTIVE_JAR" "X-CSRF-Token: $UPLOAD_CSRF")" "authenticated active user can create upload session with CSRF token"

UPLOAD_ID="$(php -r '
  $decoded = json_decode((string) file_get_contents("/tmp/auth-upload-body.json"), true, flags: JSON_THROW_ON_ERROR);
  if (!isset($decoded["id"]) || !is_string($decoded["id"])) {
      fwrite(STDERR, "Authenticated upload response has no session id.\n");
      exit(1);
  }
  echo $decoded["id"];
')"

UPLOAD_ID="$UPLOAD_ID" EXPECTED_USER_ID="$ACTIVE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$count = (int) $db->fetchOne(
    'SELECT COUNT(*) FROM upload_sessions WHERE id = :id AND user_id = :user_id',
    [
        'id' => getenv('UPLOAD_ID'),
        'user_id' => getenv('EXPECTED_USER_ID'),
    ],
);

if ($count !== 1) {
    fwrite(STDERR, "Authenticated upload was not attributed to the logged-in Mediarama user.\n");
    exit(1);
}
PHP

ACTIVE_ID="$ACTIVE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->insert('user_storage_quotas', [
    'user_id' => getenv('ACTIVE_ID'),
    'limit_bytes' => 4,
    'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
]);
PHP

expect_status 422 "$(upload_status "$ACTIVE_JAR" "X-CSRF-Token: $UPLOAD_CSRF")" "quota exhaustion returns a client error"

php -r '
  $decoded = json_decode((string) file_get_contents("/tmp/auth-upload-body.json"), true, flags: JSON_THROW_ON_ERROR);
  $expected = [
      "error" => "upload_quota_exceeded",
      "retryable" => false,
      "limit_bytes" => 4,
      "committed_bytes" => 0,
      "reserved_bytes" => 4,
      "requested_bytes" => 4,
  ];
  if ($decoded !== $expected) {
      fwrite(STDERR, "Unexpected quota error payload: ".json_encode($decoded).PHP_EOL);
      exit(1);
  }
  echo "OK quota exhaustion response is stable and actionable".PHP_EOL;
'

ACTIVE_ID="$ACTIVE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->delete('user_storage_quotas', ['user_id' => getenv('ACTIVE_ID')]);
PHP

HOME_PAGE=/tmp/auth-home.html
curl --fail --silent --show-error --cookie "$ACTIVE_JAR" --cookie-jar "$ACTIVE_JAR" "$BASE_URL/" -o "$HOME_PAGE"
LOGOUT_PATH="$(php -r '
  $html = (string) file_get_contents($argv[1]);
  if (!preg_match("/href=\"([^\"]*\/logout[^\"]*)\"/", $html, $match)) {
      fwrite(STDERR, "CSRF-protected logout URL missing from authenticated navigation.\n");
      exit(1);
  }
  echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
' "$HOME_PAGE")"
expect_status 302 "$(curl --silent --show-error --cookie "$ACTIVE_JAR" --cookie-jar "$ACTIVE_JAR" --output /tmp/auth-logout-body.html --write-out '%{http_code}' "$BASE_URL$LOGOUT_PATH")" "CSRF-protected logout succeeds"
expect_status 401 "$(upload_status "$ACTIVE_JAR")" "logout invalidates the authenticated session"

for account in inactive reset; do
    JAR="/tmp/auth-$account.cookies"
    rm -f "$JAR"
    TOKEN="$(csrf_token "$JAR" "/tmp/auth-$account-login.html")"
    expect_status 302 "$(login_status "auth-ci-$account" "$PASSWORD" "$JAR" "$TOKEN")" "$account account login is rejected"
    expect_status 401 "$(upload_status "$JAR")" "$account account cannot use authenticated upload API"
done

rm -f "$ACTIVE_JAR"
ACTIVE_TOKEN="$(csrf_token "$ACTIVE_JAR" /tmp/auth-active-login-2.html)"
expect_status 302 "$(login_status auth-ci-active "$PASSWORD" "$ACTIVE_JAR" "$ACTIVE_TOKEN")" "active user can establish a fresh session"
ACTIVE_UPLOAD_CSRF="$(api_csrf_token "$ACTIVE_JAR" /tmp/auth-upload-csrf-2.json)"

ACTIVE_ID="$ACTIVE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$replacement = password_hash('replacement-ci-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($replacement)) {
    fwrite(STDERR, "Could not create replacement CI password hash.\n");
    exit(1);
}
$db->executeStatement(
    'UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
    ['password_hash' => $replacement, 'id' => getenv('ACTIVE_ID')],
);
PHP

expect_status 401 "$(upload_status "$ACTIVE_JAR" "X-CSRF-Token: $ACTIVE_UPLOAD_CSRF")" "session is invalidated after password changes"

ACTIVE_ID="$ACTIVE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$restored = password_hash('mediarama-ci-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($restored)) {
    fwrite(STDERR, "Could not restore CI password hash.\n");
    exit(1);
}
$db->executeStatement(
    "UPDATE users
     SET password_hash = :password_hash,
         status = 'active',
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id",
    ['password_hash' => $restored, 'id' => getenv('ACTIVE_ID')],
);
PHP

rm -f "$ACTIVE_JAR"
ACTIVE_TOKEN="$(csrf_token "$ACTIVE_JAR" /tmp/auth-active-login-3.html)"
expect_status 302 "$(login_status auth-ci-active "$PASSWORD" "$ACTIVE_JAR" "$ACTIVE_TOKEN")" "active user can establish a session after password reset"
ACTIVE_UPLOAD_CSRF="$(api_csrf_token "$ACTIVE_JAR" /tmp/auth-upload-csrf-3.json)"

ACTIVE_ID="$ACTIVE_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement(
    "UPDATE users SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = :id",
    ['id' => getenv('ACTIVE_ID')],
);
PHP

expect_status 401 "$(upload_status "$ACTIVE_JAR" "X-CSRF-Token: $ACTIVE_UPLOAD_CSRF")" "session is invalidated after account status changes"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM users WHERE username LIKE 'auth-ci-%'");
PHP

# Restore the recommended installation default so this security test does not
# leak public-publishing state into unrelated integration tests.
php bin/console mediarama:platform:deployment-profile private_workspace >/tmp/auth-private-profile.txt

echo "Production authentication integration checks passed."
