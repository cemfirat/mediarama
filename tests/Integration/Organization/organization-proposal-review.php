<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionManagement;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalReview;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalReviewQuery;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalStore;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

function requireReview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function reviewUser(Connection $db, Uuid $id, string $name): void
{
    $now = '2026-09-28T18:00:00+00:00';

    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => $name.'-'.$id->toRfc4122(),
        'email' => null,
        'password_hash' => null,
        'display_name' => $name,
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}

function reviewMedia(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    string $title,
    string $cameraModel,
): void {
    $now = '2026-09-28T18:00:00+00:00';

    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'organization-review/'.$id->toRfc4122().'/source',
        'original_filename' => 'PRIVATE-'.$id->toRfc4122().'.jpg',
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
        'metadata' => '{}',
        'metadata_provenance' => '{}',
        'creator' => 'Review Owner',
        'camera_make' => 'Camera',
        'camera_model' => $cameraModel,
        'lens' => '35mm',
        'location_name' => 'Vienna',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

function reviewSmartPayload(
    string $title,
    string $cameraModel,
): OrganizationProposalPayload {
    return OrganizationProposalPayload::fromArray(
        OrganizationProposalType::SmartCollection,
        [
            'version' => 1,
            'title' => $title,
            'description' => 'Review-generated Smart Collection.',
            'rule' => [
                'version' => 1,
                'op' => 'and',
                'rules' => [[
                    'field' => 'camera_model',
                    'operator' => 'eq',
                    'value' => $cameraModel,
                ]],
            ],
        ],
    );
}

function reviewEvidence(): array
{
    return [new OrganizationEvidence(
        OrganizationEvidenceSource::Metadata,
        'Normalized metadata supports this proposal.',
    )];
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$owner = Uuid::v7();
$other = Uuid::v7();
$mediaA = Uuid::v7();
$mediaB = Uuid::v7();
$mediaStale = Uuid::v7();
$mediaScoped = Uuid::v7();
$mediaOutside = Uuid::v7();
$allMedia = [$mediaA, $mediaB, $mediaStale, $mediaScoped, $mediaOutside];

try {
    reviewUser($db, $owner, 'organization-review-owner');
    reviewUser($db, $other, 'organization-review-other');

    reviewMedia($db, $mediaA, $owner, 'Review A', 'Review Camera');
    reviewMedia($db, $mediaB, $owner, 'Review B', 'Review Camera');
    reviewMedia($db, $mediaStale, $owner, 'Review Stale', 'Stale Camera');
    reviewMedia($db, $mediaScoped, $owner, 'Review Scoped', 'Scoped Camera');
    reviewMedia($db, $mediaOutside, $owner, 'Review Outside', 'Other Camera');

    $store = new DbalOrganizationProposalStore($db);
    $review = new DbalOrganizationProposalReview(
        $db,
        new DbalSmartCollectionManagement($db),
        new SmartCollectionRuleCompiler(),
        new DbalPublicPublicationTimelineStore($db),
    );
    $query = new DbalOrganizationProposalReviewQuery($db);

    $run = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$mediaA, $mediaB],
    );
    $smartProposal = $store->addProposal(
        $owner,
        $run,
        reviewSmartPayload('Review Smart', 'Review Camera'),
        'Both selected MediaAssets share the same normalized camera model.',
        [$mediaA, $mediaB],
        reviewEvidence(),
    );
    $store->markReadyForReview($owner, $run);

    $review->edit(
        $owner,
        $smartProposal,
        reviewSmartPayload('Review Smart Edited', 'Review Camera'),
    );

    $reviewItems = $query->proposals($owner, $run, 1);
    requireReview(
        count($reviewItems) === 1
        && $reviewItems[0]->affectedMediaCount === 2
        && count($reviewItems[0]->mediaPreview) === 1
        && !property_exists($reviewItems[0]->mediaPreview[0], 'originalFilename')
        && !property_exists($reviewItems[0]->mediaPreview[0], 'metadata'),
        'review query exposes bounded safe media previews without source metadata',
    );

    try {
        $query->proposals($other, $run);
        throw new RuntimeException('Expected another user to be denied the review run.');
    } catch (Mediarama\Organization\Application\OrganizationRunUnavailableException) {
        echo "OK review query is requester-only".PHP_EOL;
    }

    $applied = $review->accept($owner, $smartProposal);
    $collection = $db->fetchAssociative(
        'SELECT owner_id, title, visibility, mode, smart_rule
         FROM collections
         WHERE id = :id',
        ['id' => $applied->entityId->toRfc4122()],
    );
    requireReview(
        $collection !== false
        && (string) $collection['owner_id'] === $owner->toRfc4122()
        && (string) $collection['title'] === 'Review Smart Edited'
        && (string) $collection['visibility'] === 'private'
        && (string) $collection['mode'] === 'smart'
        && $collection['smart_rule'] !== null
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
            ['id' => $applied->entityId->toRfc4122()],
        ) === 0,
        'accepted Smart proposal creates ordinary private dynamic Smart Collection state',
    );

    $again = $review->accept($owner, $smartProposal);
    requireReview(
        $again->alreadyApplied
        && $again->entityId->equals($applied->entityId)
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collections WHERE id = :id',
            ['id' => $applied->entityId->toRfc4122()],
        ) === 1,
        'duplicate Smart acceptance is idempotent',
    );

    requireReview(
        $store->proposal($owner, $smartProposal)->status
            === OrganizationProposalStatus::Applied,
        'applied proposal persists review state',
    );

    $rejectRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$mediaA],
    );
    $rejectProposal = $store->addProposal(
        $owner,
        $rejectRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ManualCollection,
            [
                'version' => 1,
                'title' => 'Review Rejected Collection',
                'description' => null,
            ],
        ),
        'A manual grouping suggestion for rejection testing.',
        [$mediaA],
        reviewEvidence(),
    );
    $store->markReadyForReview($owner, $rejectRun);

    $beforeReject = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
        'memberships' => (int) $db->fetchOne('SELECT COUNT(*) FROM collection_media'),
    ];
    $review->rejectMany($owner, [$rejectProposal]);
    $afterReject = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
        'memberships' => (int) $db->fetchOne('SELECT COUNT(*) FROM collection_media'),
    ];
    requireReview(
        $beforeReject === $afterReject
        && $store->proposal($owner, $rejectProposal)->status
            === OrganizationProposalStatus::Rejected,
        'rejection changes review state only',
    );

    $staleRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$mediaStale],
    );
    $staleProposal = $store->addProposal(
        $owner,
        $staleRun,
        reviewSmartPayload('Review Stale Smart', 'Stale Camera'),
        'The reviewed media matched before its metadata changed.',
        [$mediaStale],
        reviewEvidence(),
    );
    $store->markReadyForReview($owner, $staleRun);
    $db->update(
        'media_assets',
        ['camera_model' => 'Changed Camera'],
        ['id' => $mediaStale->toRfc4122()],
    );

    $collectionCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM collections WHERE owner_id = :owner',
        ['owner' => $owner->toRfc4122()],
    );
    try {
        $review->accept($owner, $staleProposal);
        throw new RuntimeException('Expected stale Smart proposal acceptance to fail.');
    } catch (DomainException) {
        echo "OK stale Smart proposal fails closed".PHP_EOL;
    }
    requireReview(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collections WHERE owner_id = :owner',
            ['owner' => $owner->toRfc4122()],
        ) === $collectionCount
        && $store->proposal($owner, $staleProposal)->status
            === OrganizationProposalStatus::PendingReview,
        'stale acceptance leaves library and proposal unchanged',
    );

    $scopeRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$mediaScoped],
    );
    $scopeProposal = $store->addProposal(
        $owner,
        $scopeRun,
        reviewSmartPayload('Review Scoped Smart', 'Scoped Camera'),
        'Only one matching MediaAsset was reviewed.',
        [$mediaScoped],
        reviewEvidence(),
    );
    $store->markReadyForReview($owner, $scopeRun);
    $db->update(
        'media_assets',
        ['camera_model' => 'Scoped Camera'],
        ['id' => $mediaOutside->toRfc4122()],
    );

    try {
        $review->accept($owner, $scopeProposal);
        throw new RuntimeException('Expected owner-wide Smart scope expansion to fail.');
    } catch (DomainException) {
        echo "OK Smart proposal cannot include owner media outside reviewed scope".PHP_EOL;
    }

    $batchRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$mediaStale],
    );
    $batchManual = $store->addProposal(
        $owner,
        $batchRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ReviewBucket,
            [
                'version' => 1,
                'title' => 'Review Atomic Bucket',
                'description' => null,
            ],
        ),
        'First batch proposal is otherwise valid.',
        [$mediaStale],
        reviewEvidence(),
    );
    usleep(2000);
    $batchSmart = $store->addProposal(
        $owner,
        $batchRun,
        reviewSmartPayload('Review Atomic Smart', 'Changed Camera'),
        'Second batch proposal will become stale.',
        [$mediaStale],
        reviewEvidence(),
    );
    $store->markReadyForReview($owner, $batchRun);
    $db->update(
        'media_assets',
        ['camera_model' => 'Changed Again'],
        ['id' => $mediaStale->toRfc4122()],
    );

    $atomicCount = (int) $db->fetchOne(
        "SELECT COUNT(*) FROM collections WHERE title = 'Review Atomic Bucket'"
    );
    try {
        $review->acceptMany($owner, [$batchManual, $batchSmart]);
        throw new RuntimeException('Expected atomic batch to fail on stale proposal.');
    } catch (DomainException) {
        echo "OK atomic batch fails as one transaction".PHP_EOL;
    }
    requireReview(
        (int) $db->fetchOne(
            "SELECT COUNT(*) FROM collections WHERE title = 'Review Atomic Bucket'"
        ) === $atomicCount
        && $store->proposal($owner, $batchManual)->status
            === OrganizationProposalStatus::PendingReview
        && $store->proposal($owner, $batchSmart)->status
            === OrganizationProposalStatus::PendingReview,
        'failed batch rolls back mutations and review statuses',
    );

    $successRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$mediaA, $mediaB],
    );
    $tagProposal = $store->addProposal(
        $owner,
        $successRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Tag,
            ['version' => 1, 'name' => 'Review Accepted Tag'],
        ),
        'Both assets should receive a normalized tag.',
        [$mediaA, $mediaB],
        reviewEvidence(),
    );
    $manualProposal = $store->addProposal(
        $owner,
        $successRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ManualCollection,
            [
                'version' => 1,
                'title' => 'Review Accepted Manual',
                'description' => 'Curated accepted group.',
            ],
        ),
        'Both assets form a curated group.',
        [$mediaA, $mediaB],
        reviewEvidence(),
    );
    $store->markReadyForReview($owner, $successRun);
    $review->acceptMany($owner, [$tagProposal, $manualProposal]);

    requireReview(
        (int) $db->fetchOne(
            "SELECT COUNT(*) FROM media_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE t.name = 'Review Accepted Tag'"
        ) === 2
        && (int) $db->fetchOne(
            "SELECT COUNT(*)
             FROM collections c
             JOIN collection_media cm ON cm.collection_id = c.id
             WHERE c.title = 'Review Accepted Manual'"
        ) === 2,
        'valid batch applies tags and private manual membership',
    );

    requireReview(
        count($store->runs($owner, 20)) >= 6,
        'requester can list recent runs for review UI',
    );

    echo "Organization proposal review/application checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM organization_runs WHERE requester_id IN (:owner, :other)',
        [
            'owner' => $owner->toRfc4122(),
            'other' => $other->toRfc4122(),
        ],
    );
    $db->executeStatement(
        "DELETE FROM collections
         WHERE owner_id = :owner
           AND title LIKE 'Review %'",
        ['owner' => $owner->toRfc4122()],
    );
    $db->executeStatement(
        'DELETE FROM media_tags
         WHERE media_id IN (:a, :b, :c, :d, :e)',
        [
            'a' => $mediaA->toRfc4122(),
            'b' => $mediaB->toRfc4122(),
            'c' => $mediaStale->toRfc4122(),
            'd' => $mediaScoped->toRfc4122(),
            'e' => $mediaOutside->toRfc4122(),
        ],
    );
    $db->executeStatement("DELETE FROM tags WHERE name LIKE 'Review %'");
    foreach ($allMedia as $mediaId) {
        $db->delete('media_assets', ['id' => $mediaId->toRfc4122()]);
    }
    $db->delete('users', ['id' => $owner->toRfc4122()]);
    $db->delete('users', ['id' => $other->toRfc4122()]);
    $db->close();
}
