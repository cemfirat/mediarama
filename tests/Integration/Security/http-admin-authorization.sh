#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"

BASE_URL="http://127.0.0.1:8082"
ORDINARY_ID="77777777-7777-4777-8777-777777777777"
ADMIN_ID="88888888-8888-4888-8888-888888888888"
GROUP_ID="99999999-9999-4999-8999-999999999999"
PASSWORD="mediarama-admin-ci-password"

php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\ParameterType;

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$db->executeStatement("DELETE FROM users WHERE username LIKE 'admin-ci-%'");
$db->delete('groups', ['id' => '99999999-9999-4999-8999-999999999999']);

$password = password_hash('mediarama-admin-ci-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($password)) {
    throw new RuntimeException('Unable to create admin CI password hash.');
}

$now = (new DateTimeImmutable())->format(DATE_ATOM);

foreach ([
    ['77777777-7777-4777-8777-777777777777', 'admin-ci-ordinary'],
    ['88888888-8888-4888-8888-888888888888', 'admin-ci-system'],
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

$db->insert(
    'groups',
    [
        'id' => '99999999-9999-4999-8999-999999999999',
        'slug' => 'admin-ci-system',
        'name' => 'Admin CI System',
        'is_system' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    ['is_system' => ParameterType::BOOLEAN],
);

$db->insert('group_permissions', [
    'group_id' => '99999999-9999-4999-8999-999999999999',
    'permission_key' => 'system.admin',
]);

$db->insert(
    'user_groups',
    [
        'user_id' => '88888888-8888-4888-8888-888888888888',
        'group_id' => '99999999-9999-4999-8999-999999999999',
        'is_primary' => true,
        'created_at' => $now,
    ],
    ['is_primary' => ParameterType::BOOLEAN],
);

$db->close();
PHP

php bin/console mediarama:platform:deployment-profile private_workspace >/tmp/admin-settings-profile.txt

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8082 -t public public/index.php >/tmp/mediarama-admin-http.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

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
        cat /tmp/mediarama-admin-http.log || true
        exit 1
    fi

    echo "OK $label"
}

get_status() {
    local jar="$1"
    local path="$2"
    local output="$3"

    local args=(
        --silent
        --show-error
        --output "$output"
        --write-out '%{http_code}'
    )

    if [ -n "$jar" ]; then
        args+=(--cookie "$jar" --cookie-jar "$jar")
    fi

    curl "${args[@]}" "$BASE_URL$path"
}

login_csrf() {
    local jar="$1"
    local page="$2"

    curl --fail --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         "$BASE_URL/login"         -o "$page"

    php -r '
      $html = (string) file_get_contents($argv[1]);
      if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
          fwrite(STDERR, "Login CSRF token missing.\n");
          exit(1);
      }
      echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$page"
}

login() {
    local username="$1"
    local jar="$2"
    local token
    token="$(login_csrf "$jar" "/tmp/admin-login-$username.html")"

    curl --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --output /tmp/admin-login-post.html         --write-out '%{http_code}'         --data-urlencode "_username=$username"         --data-urlencode "_password=$PASSWORD"         --data-urlencode "_csrf_token=$token"         "$BASE_URL/login"
}

settings_csrf() {
    local jar="$1"
    local page="$2"
    local headers="$3"

    curl --fail --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --dump-header "$headers"         "$BASE_URL/admin/settings/publication"         -o "$page"

    php -r '
      $html = (string) file_get_contents($argv[1]);
      if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
          fwrite(STDERR, "Admin settings CSRF token missing.\n");
          exit(1);
      }
      echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$page"
}

expect_status 302 "$(get_status "" "/admin" /tmp/admin-anonymous.html)" "anonymous admin dashboard redirects to authentication"
expect_status 302 "$(get_status "" "/admin/settings/publication" /tmp/admin-settings-anonymous.html)" "anonymous system settings redirect to authentication"

ORDINARY_JAR=/tmp/admin-ordinary.cookies
rm -f "$ORDINARY_JAR"
expect_status 302 "$(login admin-ci-ordinary "$ORDINARY_JAR")" "ordinary active user can authenticate"
expect_status 200 "$(get_status "$ORDINARY_JAR" "/admin" /tmp/admin-ordinary-dashboard.html)" "authenticated user can reach allowed admin dashboard"
expect_status 403 "$(get_status "$ORDINARY_JAR" "/admin/settings/publication" /tmp/admin-ordinary-settings.html)" "ordinary user cannot read system settings"

ADMIN_JAR=/tmp/admin-system.cookies
rm -f "$ADMIN_JAR"
expect_status 302 "$(login admin-ci-system "$ADMIN_JAR")" "system administrator can authenticate"

SETTINGS_TOKEN="$(settings_csrf "$ADMIN_JAR" /tmp/admin-system-settings.html /tmp/admin-system-settings.headers)"
grep -i -F "x-robots-tag: noindex, nofollow" /tmp/admin-system-settings.headers
grep -i -E '^cache-control:.*private' /tmp/admin-system-settings.headers
grep -i -E '^cache-control:.*no-store' /tmp/admin-system-settings.headers
grep -F "Publication &amp; discovery" /tmp/admin-system-settings.html

NO_CSRF_STATUS="$(curl --silent --show-error     --cookie "$ADMIN_JAR"     --cookie-jar "$ADMIN_JAR"     --output /tmp/admin-no-csrf.html     --write-out '%{http_code}'     --data-urlencode 'action=apply_profile'     --data-urlencode 'profile=public_publishing'     "$BASE_URL/admin/settings/publication")"
expect_status 403 "$NO_CSRF_STATUS" "system settings mutation rejects missing CSRF"

APPLY_STATUS="$(curl --silent --show-error     --cookie "$ADMIN_JAR"     --cookie-jar "$ADMIN_JAR"     --output /tmp/admin-apply-profile.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$SETTINGS_TOKEN"     --data-urlencode 'action=apply_profile'     --data-urlencode 'profile=public_publishing'     "$BASE_URL/admin/settings/publication")"
expect_status 302 "$APPLY_STATUS" "system administrator can apply Public publishing preset"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$row = $db->fetchAssociative(
    'SELECT deployment_profile, public_publishing_enabled, search_index_default
     FROM platform_settings
     WHERE id = 1'
);

$enabled = in_array(
    strtolower((string) ($row['public_publishing_enabled'] ?? '')),
    ['1', 't', 'true', 'yes', 'on'],
    true,
);

if (
    ($row['deployment_profile'] ?? null) !== 'public_publishing'
    || !$enabled
    || ($row['search_index_default'] ?? null) !== 'index'
) {
    throw new RuntimeException('Public publishing preset was not persisted correctly.');
}

echo "OK system administrator mutation persisted through application boundary\n";
$db->close();
PHP

SETTINGS_TOKEN="$(settings_csrf "$ADMIN_JAR" /tmp/admin-system-settings-after-profile.html /tmp/admin-system-settings-after-profile.headers)"

SAVE_STATUS="$(curl --silent --show-error     --cookie "$ADMIN_JAR"     --cookie-jar "$ADMIN_JAR"     --output /tmp/admin-save-settings.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$SETTINGS_TOKEN"     --data-urlencode 'action=save_settings'     --data-urlencode 'search_index_default=noindex'     "$BASE_URL/admin/settings/publication")"
expect_status 302 "$SAVE_STATUS" "system administrator can save independent publication settings"

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$row = $db->fetchAssociative(
    'SELECT public_publishing_enabled, search_index_default
     FROM platform_settings
     WHERE id = 1'
);

$enabled = in_array(
    strtolower((string) ($row['public_publishing_enabled'] ?? '')),
    ['1', 't', 'true', 'yes', 'on'],
    true,
);

if ($enabled || ($row['search_index_default'] ?? null) !== 'noindex') {
    throw new RuntimeException('Independent publication settings were not persisted correctly.');
}

echo "OK independent publication settings persisted\n";
$db->close();
PHP

php bin/console mediarama:platform:deployment-profile private_workspace >/tmp/admin-settings-profile-restore.txt

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("DELETE FROM users WHERE username LIKE 'admin-ci-%'");
$db->delete('groups', ['id' => '99999999-9999-4999-8999-999999999999']);
$db->close();
PHP

echo "Admin authorization and publication-settings HTTP checks passed."
