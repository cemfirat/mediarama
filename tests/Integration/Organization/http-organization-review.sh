#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set}"

BASE_URL="http://127.0.0.1:8096"
OWNER_ID="88888888-8888-4888-8888-888888888881"
OTHER_ID="88888888-8888-4888-8888-888888888882"
PASSWORD="mediarama-organization-review-ci"
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
$db = Doctrine\DBAL\DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);
$owner = '88888888-8888-4888-8888-888888888881';
$other = '88888888-8888-4888-8888-888888888882';
$media = [];
for ($i = 1; $i <= 8; ++$i) {
    $media[] = sprintf('88888888-8888-4888-8888-88888888889%d', $i);
}
$db->executeStatement(
    'DELETE FROM organization_runs WHERE requester_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
$db->executeStatement(
    'DELETE FROM collections WHERE owner_id IN (:owner, :other)',
    ['owner' => $owner, 'other' => $other],
);
foreach ($media as $id) {
    $db->delete('media_assets', ['id' => $id]);
}
$db->executeStatement(
    "DELETE FROM tags WHERE name LIKE 'HTTP review %' OR name LIKE 'HTTP reject %'"
);
$db->delete('users', ['id' => $owner]);
$db->delete('users', ['id' => $other]);
$db->close();
PHP
}
trap cleanup EXIT

php <<'PHP'
<?php
require 'vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalStore;
use Symfony\Component\Uid\Uuid;

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$owner = Uuid::fromString('88888888-8888-4888-8888-888888888881');
$other = Uuid::fromString('88888888-8888-4888-8888-888888888882');
$password = password_hash(
    'mediarama-organization-review-ci',
    PASSWORD_DEFAULT,
);
$now = '2026-09-28T16:00:00+00:00';

foreach ([
    [$owner, 'organization-http-owner'],
    [$other, 'organization-http-other'],
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

$media = [];
for ($i = 1; $i <= 8; ++$i) {
    $id = Uuid::fromString(
        sprintf('88888888-8888-4888-8888-88888888889%d', $i),
    );
    $media[$i] = $id;

    $location = match (true) {
        $i <= 2 => 'Vienna',
        $i === 3 => 'Graz',
        default => 'Salzburg',
    };

    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'organization-http/'.$id->toRfc4122().'/source',
        'original_filename' => 'PRIVATE-ORGANIZATION-'.$i.'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => 'Organization HTTP '.$i,
        'description' => null,
        'captured_at' => '2026-09-15T10:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => json_encode([
            'PRIVATE_RAW_ORGANIZATION_SENTINEL' => 'must-never-render',
        ], JSON_THROW_ON_ERROR),
        'metadata_provenance' => '{}',
        'creator' => 'HTTP Review Owner',
        'copyright' => null,
        'camera_make' => 'Fixture',
        'camera_model' => $i >= 4 ? 'Salzburg Camera' : 'Review Camera',
        'lens' => $i >= 4 ? '50mm' : '35mm',
        'iso' => null,
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

$store = new DbalOrganizationProposalStore($db);
$run = $store->createRun(
    $owner,
    OrganizationProducer::metadata(),
    [$media[1], $media[2], $media[3]],
);

$evidence = static fn (string $summary): array => [
    new OrganizationEvidence(
        OrganizationEvidenceSource::Metadata,
        $summary,
    ),
];

$smart = $store->addProposal(
    $owner,
    $run,
    OrganizationProposalPayload::fromArray(
        OrganizationProposalType::SmartCollection,
        [
            'version' => 1,
            'title' => 'HTTP review Smart',
            'description' => 'Review before applying.',
            'rule' => [
                'version' => 1,
                'op' => 'and',
                'rules' => [[
                    'field' => 'location_name',
                    'operator' => 'eq',
                    'value' => 'Vienna',
                ]],
            ],
        ],
    ),
    'Two selected MediaAssets share the reviewed coarse location.',
    [$media[1], $media[2]],
    $evidence('Normalized coarse location evidence.'),
);

$tag = $store->addProposal(
    $owner,
    $run,
    OrganizationProposalPayload::fromArray(
        OrganizationProposalType::Tag,
        ['version' => 1, 'name' => 'HTTP review tag'],
    ),
    'One selected MediaAsset needs a reviewed normalized tag.',
    [$media[3]],
    $evidence('Tag proposal fixture evidence.'),
);

$bucket = $store->addProposal(
    $owner,
    $run,
    OrganizationProposalPayload::fromArray(
        OrganizationProposalType::ReviewBucket,
        [
            'version' => 1,
            'title' => 'HTTP review bucket',
            'description' => 'Private manual review bucket.',
        ],
    ),
    'Keep one item in a private manual review bucket.',
    [$media[2]],
    $evidence('Review bucket fixture evidence.'),
);

$reject = $store->addProposal(
    $owner,
    $run,
    OrganizationProposalPayload::fromArray(
        OrganizationProposalType::Tag,
        ['version' => 1, 'name' => 'HTTP reject tag'],
    ),
    'This proposal exists to verify explicit rejection.',
    [$media[1]],
    $evidence('Rejected proposal fixture evidence.'),
);

$store->markReadyForReview($owner, $run);

foreach ([
    'run' => $run,
    'smart' => $smart,
    'tag' => $tag,
    'bucket' => $bucket,
    'reject' => $reject,
] as $name => $id) {
    file_put_contents(
        '/tmp/organization-http-'.$name,
        $id->toRfc4122(),
    );
}

$db->close();
PHP

RUN_ID="$(cat /tmp/organization-http-run)"
SMART_ID="$(cat /tmp/organization-http-smart)"
TAG_ID="$(cat /tmp/organization-http-tag)"
BUCKET_ID="$(cat /tmp/organization-http-bucket)"
REJECT_ID="$(cat /tmp/organization-http-reject)"

APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8096 -t public public/index.php >/tmp/mediarama-organization-review-http.log 2>&1 &
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
        cat /tmp/mediarama-organization-review-http.log || true
        exit 1
    fi

    echo "OK $label"
}

login() {
    local username="$1"
    local jar="$2"
    local page="/tmp/organization-login-$username.html"

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
        --output /tmp/organization-login-post.html \
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

OWNER_JAR=/tmp/organization-owner.cookies
OTHER_JAR=/tmp/organization-other.cookies

expect_status 302 "$(login organization-http-owner "$OWNER_JAR")" "organization owner can authenticate"
expect_status 302 "$(login organization-http-other "$OTHER_JAR")" "second user can authenticate"

LIBRARY_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-library-no-provider.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library")"
expect_status 200 "$LIBRARY_STATUS" "deterministic Library organization remains available without an AI provider"
grep -F 'Analyze selected' /tmp/organization-library-no-provider.html >/dev/null
! grep -F 'AI-assisted analysis' /tmp/organization-library-no-provider.html >/dev/null
! grep -F 'Browser Test AI' /tmp/organization-library-no-provider.html >/dev/null

RUN_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --dump-header /tmp/organization-run.headers \
    --output /tmp/organization-run.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library/organization/runs/$RUN_ID")"
expect_status 200 "$RUN_STATUS" "owner can open private organization review"

grep -i -F 'x-robots-tag: noindex, nofollow' /tmp/organization-run.headers >/dev/null
grep -i -E '^cache-control:.*no-store' /tmp/organization-run.headers >/dev/null
grep -F 'HTTP review Smart' /tmp/organization-run.html >/dev/null
grep -F 'Two selected MediaAssets share the reviewed coarse location.' /tmp/organization-run.html >/dev/null
grep -F 'Normalized coarse location evidence.' /tmp/organization-run.html >/dev/null
grep -F 'Affected media preview' /tmp/organization-run.html >/dev/null
grep -F 'ordinary dynamic Smart Collection' /tmp/organization-run.html >/dev/null
! grep -F 'PRIVATE_RAW_ORGANIZATION_SENTINEL' /tmp/organization-run.html >/dev/null
! grep -F 'PRIVATE-ORGANIZATION-' /tmp/organization-run.html >/dev/null
! grep -F '48.123456' /tmp/organization-run.html >/dev/null
! grep -F '16.654321' /tmp/organization-run.html >/dev/null

OTHER_RUN_STATUS="$(curl --silent --show-error \
    --cookie "$OTHER_JAR" --cookie-jar "$OTHER_JAR" \
    --output /tmp/organization-other-run.html \
    --write-out '%{http_code}' \
    "$BASE_URL/library/organization/runs/$RUN_ID")"
expect_status 404 "$OTHER_RUN_STATUS" "another user cannot inspect the owner's review run"

NO_APPLY_CSRF="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-no-apply-csrf.html \
    --write-out '%{http_code}' \
    --request POST \
    "$BASE_URL/library/organization/proposals/$SMART_ID/apply")"
expect_status 403 "$NO_APPLY_CSRF" "proposal application rejects missing CSRF"

EDIT_TOKEN="$(form_token /tmp/organization-run.html "/library/organization/proposals/$SMART_ID/edit")"
EDIT_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-edit.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$EDIT_TOKEN" \
    --data-urlencode 'changes[title]=Reviewed HTTP Smart' \
    --data-urlencode 'changes[description]=Reviewed in authenticated organization UI.' \
    "$BASE_URL/library/organization/proposals/$SMART_ID/edit")"
expect_status 302 "$EDIT_STATUS" "owner can edit a proposal before acceptance"

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library/organization/runs/$RUN_ID" \
    -o /tmp/organization-run-edited.html
grep -F 'Reviewed HTTP Smart' /tmp/organization-run-edited.html >/dev/null

APPLY_TOKEN="$(form_token /tmp/organization-run-edited.html "/library/organization/proposals/$SMART_ID/apply")"
APPLY_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-apply.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$APPLY_TOKEN" \
    "$BASE_URL/library/organization/proposals/$SMART_ID/apply")"
expect_status 302 "$APPLY_STATUS" "accepted Smart proposal applies through authenticated review"

SMART_PROPOSAL_ID="$SMART_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$proposal = $db->fetchAssociative(
    'SELECT status, applied_resource_id
     FROM organization_proposals
     WHERE id = :proposal',
    ['proposal' => (string) getenv('SMART_PROPOSAL_ID')],
);
if ($proposal === false || $proposal['status'] !== 'applied' || $proposal['applied_resource_id'] === null) {
    throw new RuntimeException('Smart proposal was not persisted as applied.');
}
$collection = $db->fetchAssociative(
    'SELECT title, visibility, mode
     FROM collections
     WHERE id = :collection',
    ['collection' => (string) $proposal['applied_resource_id']],
);
if (
    $collection === false
    || $collection['title'] !== 'Reviewed HTTP Smart'
    || $collection['visibility'] !== 'private'
    || $collection['mode'] !== 'smart'
) {
    throw new RuntimeException('Accepted Smart proposal did not create ordinary private Smart state.');
}
$db->close();
echo "OK accepted Smart proposal remains private\n";
PHP

DUPLICATE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-duplicate-apply.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$APPLY_TOKEN" \
    "$BASE_URL/library/organization/proposals/$SMART_ID/apply")"
expect_status 302 "$DUPLICATE_STATUS" "duplicate acceptance is safely retryable"

SMART_PROPOSAL_ID="$SMART_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$id = $db->fetchOne(
    'SELECT applied_resource_id FROM organization_proposals WHERE id = :proposal',
    ['proposal' => (string) getenv('SMART_PROPOSAL_ID')],
);
$count = (int) $db->fetchOne(
    "SELECT COUNT(*) FROM collections WHERE id = :id AND title = 'Reviewed HTTP Smart'",
    ['id' => (string) $id],
);
if ($count !== 1) {
    throw new RuntimeException('Duplicate acceptance created duplicate Smart Collection state.');
}
$db->close();
echo "OK duplicate acceptance reuses one resource identity\n";
PHP

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library/organization/runs/$RUN_ID" \
    -o /tmp/organization-run-bulk.html
BULK_TOKEN="$(form_token /tmp/organization-run-bulk.html "/library/organization/runs/$RUN_ID/bulk-review")"

BULK_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-bulk.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$BULK_TOKEN" \
    --data-urlencode 'action=apply' \
    --data-urlencode "proposal_ids[]=$TAG_ID" \
    --data-urlencode "proposal_ids[]=$BUCKET_ID" \
    "$BASE_URL/library/organization/runs/$RUN_ID/bulk-review")"
expect_status 302 "$BULK_STATUS" "owner can apply selected proposals"

TAG_PROPOSAL_ID="$TAG_ID" BUCKET_PROPOSAL_ID="$BUCKET_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
foreach (['TAG_PROPOSAL_ID', 'BUCKET_PROPOSAL_ID'] as $env) {
    $status = $db->fetchOne(
        'SELECT status FROM organization_proposals WHERE id = :id',
        ['id' => (string) getenv($env)],
    );
    if ($status !== 'applied') {
        throw new RuntimeException($env.' was not applied.');
    }
}
$tagMembership = (int) $db->fetchOne(
    "SELECT COUNT(*)
     FROM media_tags mt
     JOIN tags t ON t.id = mt.tag_id
     WHERE mt.media_id = '88888888-8888-4888-8888-888888888893'
       AND t.name = 'HTTP review tag'"
);
$bucket = (int) $db->fetchOne(
    "SELECT COUNT(*)
     FROM collections c
     JOIN collection_media cm ON cm.collection_id = c.id
     WHERE c.owner_id = '88888888-8888-4888-8888-888888888881'
       AND c.title = 'HTTP review bucket'
       AND c.visibility = 'private'
       AND c.mode = 'manual'
       AND cm.media_id = '88888888-8888-4888-8888-888888888892'"
);
if ($tagMembership !== 1 || $bucket !== 1) {
    throw new RuntimeException('Bulk application did not use normal tag/manual Collection boundaries.');
}
$db->close();
echo "OK selected proposals applied through normal private library boundaries\n";
PHP

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library/organization/runs/$RUN_ID" \
    -o /tmp/organization-run-reject.html
REJECT_TOKEN="$(form_token /tmp/organization-run-reject.html "/library/organization/proposals/$REJECT_ID/reject")"
REJECT_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-reject.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$REJECT_TOKEN" \
    "$BASE_URL/library/organization/proposals/$REJECT_ID/reject")"
expect_status 302 "$REJECT_STATUS" "owner can explicitly reject a proposal"

REJECT_PROPOSAL_ID="$REJECT_ID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$dsn = new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$status = $db->fetchOne(
    'SELECT status FROM organization_proposals WHERE id = :id',
    ['id' => (string) getenv('REJECT_PROPOSAL_ID')],
);
$tagCount = (int) $db->fetchOne(
    "SELECT COUNT(*) FROM tags WHERE name = 'HTTP reject tag'"
);
if ($status !== 'rejected' || $tagCount !== 0) {
    throw new RuntimeException('Rejected proposal mutated normal library state.');
}
$db->close();
echo "OK rejected proposal remains non-mutating\n";
PHP

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL/library" \
    -o /tmp/organization-library.html
ANALYZE_TOKEN="$(form_token /tmp/organization-library.html '/library/organization/analyze')"

NO_ANALYZE_CSRF="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --output /tmp/organization-no-analyze-csrf.html \
    --write-out '%{http_code}' \
    --data-urlencode 'media_ids[]=88888888-8888-4888-8888-888888888894' \
    "$BASE_URL/library/organization/analyze")"
expect_status 403 "$NO_ANALYZE_CSRF" "organization analysis rejects missing CSRF"

ANALYZE_STATUS="$(curl --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    --dump-header /tmp/organization-analyze.headers \
    --output /tmp/organization-analyze.html \
    --write-out '%{http_code}' \
    --data-urlencode "_csrf_token=$ANALYZE_TOKEN" \
    --data-urlencode 'media_ids[]=88888888-8888-4888-8888-888888888894' \
    --data-urlencode 'media_ids[]=88888888-8888-4888-8888-888888888895' \
    --data-urlencode 'media_ids[]=88888888-8888-4888-8888-888888888896' \
    --data-urlencode 'media_ids[]=88888888-8888-4888-8888-888888888897' \
    --data-urlencode 'media_ids[]=88888888-8888-4888-8888-888888888898' \
    "$BASE_URL/library/organization/analyze")"
expect_status 302 "$ANALYZE_STATUS" "selected Library media can start deterministic organization analysis"

ANALYZE_LOCATION="$(awk 'BEGIN {IGNORECASE=1} /^location:/ {gsub("\r", ""); print $2}' /tmp/organization-analyze.headers | tail -n 1)"
if [[ "$ANALYZE_LOCATION" != /library/organization/runs/* ]]; then
    echo "FAIL organization analysis did not redirect to its private review run"
    cat /tmp/organization-analyze.headers
    exit 1
fi

curl --fail --silent --show-error \
    --cookie "$OWNER_JAR" --cookie-jar "$OWNER_JAR" \
    "$BASE_URL$ANALYZE_LOCATION" \
    -o /tmp/organization-analyzed-run.html
grep -F 'Analysis completed. Review the proposals before applying anything.' /tmp/organization-analyzed-run.html >/dev/null
grep -F 'Salzburg' /tmp/organization-analyzed-run.html >/dev/null
! grep -F 'PRIVATE_RAW_ORGANIZATION_SENTINEL' /tmp/organization-analyzed-run.html >/dev/null

echo "Authenticated organization review HTTP checks passed."
