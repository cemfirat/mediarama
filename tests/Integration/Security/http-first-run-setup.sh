#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"
: "${MEDIARAMA_SETUP_TOKEN:?MEDIARAMA_SETUP_TOKEN must be set}"

BASE_URL="http://127.0.0.1:8084"
PASSWORD="mediarama-first-run-ci-password"
SETUP_TOKEN="$MEDIARAMA_SETUP_TOKEN"

expect_status() {
    local expected="$1"
    local actual="$2"
    local label="$3"

    if [ "$actual" != "$expected" ]; then
        echo "FAIL $label: expected HTTP $expected, got $actual"
        cat /tmp/mediarama-setup-http.log 2>/dev/null || true
        exit 1
    fi

    echo "OK $label"
}

reset_setup() {
    php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$db->executeStatement("DELETE FROM users WHERE username LIKE 'setup-ci-%'");
$db->executeStatement(
    "DELETE FROM groups
     WHERE slug = 'mediarama-administrators'
        OR slug LIKE 'setup-ci-%'"
);
$db->executeStatement(
    "UPDATE platform_settings
     SET deployment_profile = 'private_workspace',
         public_publishing_enabled = FALSE,
         search_index_default = 'noindex',
         setup_status = 'pending',
         setup_completed_at = NULL,
         setup_completed_by = NULL,
         setup_completed_via = NULL,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = 1"
);
$db->close();
PHP
}

create_admin_fixture() {
    local username="$1"
    local status="$2"
    local suffix="$3"

    FIXTURE_USERNAME="$username" FIXTURE_STATUS="$status" FIXTURE_SUFFIX="$suffix" php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\ParameterType;

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$suffix = (string) getenv('FIXTURE_SUFFIX');

// Deterministic valid UUIDs keep fixture identities stable and easy to inspect.
$userId = match ($suffix) {
    'a' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'b' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'c' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    default => throw new RuntimeException('Unknown fixture suffix.'),
};
$groupId = match ($suffix) {
    'a' => 'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa',
    'b' => 'bbbbbbbb-1111-4bbb-8bbb-bbbbbbbbbbbb',
    'c' => 'cccccccc-1111-4ccc-8ccc-cccccccccccc',
    default => throw new RuntimeException('Unknown fixture suffix.'),
};

$now = (new DateTimeImmutable())->format(DATE_ATOM);
$existingPassword = password_hash('existing-fixture-password', PASSWORD_BCRYPT, ['cost' => 4]);
if (!is_string($existingPassword)) {
    throw new RuntimeException('Unable to hash fixture password.');
}

$db->insert('users', [
    'id' => $userId,
    'username' => (string) getenv('FIXTURE_USERNAME'),
    'email' => null,
    'password_hash' => $existingPassword,
    'display_name' => (string) getenv('FIXTURE_USERNAME'),
    'status' => (string) getenv('FIXTURE_STATUS'),
    'locale' => 'en',
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => null,
]);

$db->insert(
    'groups',
    [
        'id' => $groupId,
        'slug' => 'setup-ci-'.$suffix,
        'name' => 'Setup CI '.$suffix,
        'is_system' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    ['is_system' => ParameterType::BOOLEAN],
);

$db->insert('group_permissions', [
    'group_id' => $groupId,
    'permission_key' => 'system.admin',
]);

$db->insert(
    'user_groups',
    [
        'user_id' => $userId,
        'group_id' => $groupId,
        'is_primary' => true,
        'created_at' => $now,
    ],
    ['is_primary' => ParameterType::BOOLEAN],
);

file_put_contents('/tmp/setup-fixture-user-id', $userId);
file_put_contents('/tmp/setup-fixture-password-hash', $existingPassword);
$db->close();
PHP
}

setup_csrf() {
    local jar="$1"
    local page="$2"
    local headers="$3"

    curl --fail --silent --show-error         --cookie "$jar"         --cookie-jar "$jar"         --dump-header "$headers"         "$BASE_URL/setup"         -o "$page"

    php -r '
      $html = (string) file_get_contents($argv[1]);
      if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $match)) {
          fwrite(STDERR, "Setup CSRF token missing.\n");
          exit(1);
      }
      echo html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    ' "$page"
}

reset_setup

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$row = $db->fetchAssociative(
    'SELECT setup_status, setup_completed_at, setup_completed_by, setup_completed_via
     FROM platform_settings
     WHERE id = 1'
);

if (
    ($row['setup_status'] ?? null) !== 'pending'
    || $row['setup_completed_at'] !== null
    || $row['setup_completed_by'] !== null
    || $row['setup_completed_via'] !== null
) {
    throw new RuntimeException('Fresh setup state is not safely pending.');
}
echo "OK fresh installation setup state is pending\n";
$db->close();
PHP

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8084 -t public public/index.php >/tmp/mediarama-setup-http.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; reset_setup' EXIT

for _ in $(seq 1 50); do
    STATUS="$(curl --silent --output /dev/null --write-out '%{http_code}' "$BASE_URL/setup" || true)"
    if [ "$STATUS" = "200" ]; then
        break
    fi
    sleep 0.2
done

ROOT_HEADERS=/tmp/setup-root.headers
ROOT_STATUS="$(curl --silent --show-error --dump-header "$ROOT_HEADERS" --output /tmp/setup-root.body --write-out '%{http_code}' "$BASE_URL/")"
expect_status 302 "$ROOT_STATUS" "anonymous fresh-install home redirects into first-run setup"
grep -i -E '^location:.*/setup' "$ROOT_HEADERS"

SETUP_JAR=/tmp/setup-browser.cookies
rm -f "$SETUP_JAR"
SETUP_CSRF="$(setup_csrf "$SETUP_JAR" /tmp/setup-page.html /tmp/setup-page.headers)"
grep -i -F "x-robots-tag: noindex, nofollow" /tmp/setup-page.headers
grep -i -E '^cache-control:.*private' /tmp/setup-page.headers
grep -i -E '^cache-control:.*no-store' /tmp/setup-page.headers
if grep -F "$SETUP_TOKEN" /tmp/setup-page.html; then
    echo "FAIL setup page exposed the server-side setup token"
    exit 1
fi

INVALID_STATUS="$(curl --silent --show-error     --cookie "$SETUP_JAR"     --cookie-jar "$SETUP_JAR"     --output /tmp/setup-invalid.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$SETUP_CSRF"     --data-urlencode 'setup_token=wrong-setup-token'     --data-urlencode 'username=setup-ci-browser'     --data-urlencode "password=$PASSWORD"     --data-urlencode "password_confirmation=$PASSWORD"     --data-urlencode 'deployment_profile=private_workspace'     "$BASE_URL/setup")"
expect_status 403 "$INVALID_STATUS" "browser bootstrap rejects an invalid setup token"
if grep -F "$PASSWORD" /tmp/setup-invalid.html || grep -F "$SETUP_TOKEN" /tmp/setup-invalid.html; then
    echo "FAIL setup error response exposed credentials"
    exit 1
fi

SETUP_CSRF="$(setup_csrf "$SETUP_JAR" /tmp/setup-page-valid.html /tmp/setup-page-valid.headers)"

VALID_STATUS="$(curl --silent --show-error     --cookie "$SETUP_JAR"     --cookie-jar "$SETUP_JAR"     --dump-header /tmp/setup-valid.headers     --output /tmp/setup-valid.body     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$SETUP_CSRF"     --data-urlencode "setup_token=$SETUP_TOKEN"     --data-urlencode 'username=setup-ci-browser'     --data-urlencode 'email=setup-ci-browser@example.test'     --data-urlencode "password=$PASSWORD"     --data-urlencode "password_confirmation=$PASSWORD"     --data-urlencode 'deployment_profile=private_workspace'     "$BASE_URL/setup")"
expect_status 302 "$VALID_STATUS" "browser bootstrap creates and signs in the first administrator"
grep -i -E '^location:.*/admin' /tmp/setup-valid.headers
if grep -F "$PASSWORD" /tmp/setup-valid.body || grep -F "$SETUP_TOKEN" /tmp/setup-valid.body; then
    echo "FAIL successful setup response exposed credentials"
    exit 1
fi

ADMIN_STATUS="$(curl --silent --show-error     --cookie "$SETUP_JAR"     --cookie-jar "$SETUP_JAR"     --output /tmp/setup-admin.html     --write-out '%{http_code}'     "$BASE_URL/admin")"
expect_status 200 "$ADMIN_STATUS" "new administrator session can enter the protected admin area"

EXPECTED_PASSWORD="$PASSWORD" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$user = $db->fetchAssociative(
    "SELECT id, password_hash, status
     FROM users
     WHERE username = 'setup-ci-browser'"
);
if ($user === false || ($user['status'] ?? null) !== 'active') {
    throw new RuntimeException('Browser bootstrap did not create an active administrator.');
}
if (!password_verify((string) getenv('EXPECTED_PASSWORD'), (string) $user['password_hash'])) {
    throw new RuntimeException('Browser bootstrap password was not stored through the password hasher.');
}

$permission = (bool) $db->fetchOne(
    "SELECT EXISTS (
        SELECT 1
        FROM user_groups ug
        INNER JOIN group_permissions gp ON gp.group_id = ug.group_id
        WHERE ug.user_id = :user
          AND gp.permission_key = 'system.admin'
    )",
    ['user' => $user['id']],
);
if (!$permission) {
    throw new RuntimeException('First administrator does not receive system.admin through group membership.');
}

$settings = $db->fetchAssociative(
    'SELECT deployment_profile, public_publishing_enabled, search_index_default,
            setup_status, setup_completed_by, setup_completed_via
     FROM platform_settings
     WHERE id = 1'
);
$publishing = in_array(
    strtolower((string) ($settings['public_publishing_enabled'] ?? '')),
    ['1', 't', 'true', 'yes', 'on'],
    true,
);
if (
    ($settings['setup_status'] ?? null) !== 'completed'
    || ($settings['setup_completed_by'] ?? null) !== $user['id']
    || ($settings['setup_completed_via'] ?? null) !== 'browser'
    || ($settings['deployment_profile'] ?? null) !== 'private_workspace'
    || $publishing
    || ($settings['search_index_default'] ?? null) !== 'noindex'
) {
    throw new RuntimeException('Browser bootstrap did not persist the safe setup/profile state atomically.');
}
echo "OK browser bootstrap persisted administrator, capability and Private workspace state\n";
$db->close();
PHP

REPEAT_STATUS="$(curl --silent --show-error     --cookie "$SETUP_JAR"     --cookie-jar "$SETUP_JAR"     --output /tmp/setup-repeat.html     --write-out '%{http_code}'     --data-urlencode "_csrf_token=$SETUP_CSRF"     --data-urlencode "setup_token=$SETUP_TOKEN"     --data-urlencode 'username=setup-ci-second'     --data-urlencode "password=$PASSWORD"     --data-urlencode "password_confirmation=$PASSWORD"     --data-urlencode 'deployment_profile=public_publishing'     "$BASE_URL/setup")"
expect_status 409 "$REPEAT_STATUS" "browser bootstrap write path fails closed after completion"

kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true
trap 'reset_setup' EXIT

reset_setup
create_admin_fixture setup-ci-imported password_reset_required a

printf '%s\n' "$PASSWORD" | php bin/console mediarama:setup:bootstrap-admin     setup-ci-imported     --deployment-profile=internal_isolated     --password-stdin     > /tmp/setup-cli-imported.txt

if grep -F "$PASSWORD" /tmp/setup-cli-imported.txt; then
    echo "FAIL CLI bootstrap exposed the password"
    exit 1
fi

EXPECTED_PASSWORD="$PASSWORD" php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$users = $db->fetchAllAssociative(
    "SELECT id, username, password_hash, status
     FROM users
     WHERE username LIKE 'setup-ci-%'"
);
if (count($users) !== 1 || ($users[0]['username'] ?? null) !== 'setup-ci-imported') {
    throw new RuntimeException('Imported administrator recovery created a duplicate identity.');
}
if (($users[0]['status'] ?? null) !== 'active') {
    throw new RuntimeException('Imported password-reset administrator was not activated.');
}
if (!password_verify((string) getenv('EXPECTED_PASSWORD'), (string) $users[0]['password_hash'])) {
    throw new RuntimeException('Recovered imported administrator password was not updated.');
}

$settings = $db->fetchAssociative(
    'SELECT deployment_profile, public_publishing_enabled, search_index_default,
            setup_status, setup_completed_by, setup_completed_via
     FROM platform_settings
     WHERE id = 1'
);
$publishing = in_array(
    strtolower((string) ($settings['public_publishing_enabled'] ?? '')),
    ['1', 't', 'true', 'yes', 'on'],
    true,
);
if (
    ($settings['setup_status'] ?? null) !== 'completed'
    || ($settings['setup_completed_by'] ?? null) !== $users[0]['id']
    || ($settings['setup_completed_via'] ?? null) !== 'cli'
    || ($settings['deployment_profile'] ?? null) !== 'internal_isolated'
    || $publishing
    || ($settings['search_index_default'] ?? null) !== 'noindex'
) {
    throw new RuntimeException('Imported administrator recovery did not persist setup state correctly.');
}
echo "OK imported administrator is recovered in place without duplicate superuser\n";
$db->close();
PHP

set +e
printf '%s\n' "$PASSWORD" | php bin/console mediarama:setup:bootstrap-admin     setup-ci-imported     --password-stdin     > /tmp/setup-cli-repeat.txt 2>&1
CLI_REPEAT_STATUS=$?
set -e
if [ "$CLI_REPEAT_STATUS" -eq 0 ]; then
    echo "FAIL CLI bootstrap remained writable after setup completion"
    cat /tmp/setup-cli-repeat.txt
    exit 1
fi
if grep -F "$PASSWORD" /tmp/setup-cli-repeat.txt; then
    echo "FAIL repeated CLI bootstrap exposed the password"
    exit 1
fi
echo "OK CLI bootstrap fails closed after completion"

reset_setup
create_admin_fixture setup-ci-inactive inactive b

set +e
printf '%s\n' "$PASSWORD" | php bin/console mediarama:setup:bootstrap-admin     setup-ci-inactive     --password-stdin     > /tmp/setup-cli-inactive-denied.txt 2>&1
INACTIVE_DENIED_STATUS=$?
set -e
if [ "$INACTIVE_DENIED_STATUS" -eq 0 ]; then
    echo "FAIL inactive administrator was silently reactivated without explicit recovery"
    exit 1
fi

printf '%s\n' "$PASSWORD" | php bin/console mediarama:setup:bootstrap-admin     setup-ci-inactive     --password-stdin     --recover-inactive     > /tmp/setup-cli-inactive-recovered.txt

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$status = $db->fetchOne("SELECT status FROM users WHERE username = 'setup-ci-inactive'");
$setup = $db->fetchOne('SELECT setup_status FROM platform_settings WHERE id = 1');
if ($status !== 'active' || $setup !== 'completed') {
    throw new RuntimeException('Explicit inactive-administrator recovery did not complete.');
}
echo "OK inactive administrator requires explicit server-side recovery opt-in\n";
$db->close();
PHP

reset_setup
create_admin_fixture setup-ci-existing active c

set +e
printf '%s\n' "$PASSWORD" | php bin/console mediarama:setup:bootstrap-admin     setup-ci-would-be-duplicate     --deployment-profile=public_publishing     --password-stdin     > /tmp/setup-cli-existing.txt 2>&1
EXISTING_STATUS=$?
set -e
if [ "$EXISTING_STATUS" -ne 0 ]; then
    echo "FAIL existing active administrator reconciliation failed"
    cat /tmp/setup-cli-existing.txt
    exit 1
fi

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$count = (int) $db->fetchOne("SELECT COUNT(*) FROM users WHERE username LIKE 'setup-ci-%'");
$duplicate = (int) $db->fetchOne("SELECT COUNT(*) FROM users WHERE username = 'setup-ci-would-be-duplicate'");
$existing = $db->fetchAssociative(
    "SELECT id, password_hash, status FROM users WHERE username = 'setup-ci-existing'"
);
$settings = $db->fetchAssociative(
    'SELECT deployment_profile, setup_status, setup_completed_by, setup_completed_via
     FROM platform_settings
     WHERE id = 1'
);
$originalHash = trim((string) file_get_contents('/tmp/setup-fixture-password-hash'));

if (
    $count !== 1
    || $duplicate !== 0
    || $existing === false
    || ($existing['status'] ?? null) !== 'active'
    || !hash_equals($originalHash, (string) $existing['password_hash'])
    || ($settings['setup_status'] ?? null) !== 'completed'
    || ($settings['setup_completed_by'] ?? null) !== $existing['id']
    || ($settings['setup_completed_via'] ?? null) !== 'existing_admin'
    || ($settings['deployment_profile'] ?? null) !== 'private_workspace'
) {
    throw new RuntimeException('Existing active administrator reconciliation changed credentials/profile or created a duplicate.');
}
echo "OK existing active administrator closes bootstrap without duplicate or policy mutation\n";
$db->close();
PHP

reset_setup
trap - EXIT

echo "First-run setup integration checks passed."
