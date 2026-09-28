<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Infrastructure\Persistence\DbalManualCollectionManagement;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionManagement;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaAssetRepository;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaTagManagement;
use Mediarama\Organization\Application\OrganizationProposalStaleException;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationMetadataSnapshotQuery;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalApplication;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalStore;
use Mediarama\Organization\Infrastructure\Persistence\DbalOwnedPresentationManagement;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

function requireOrganizationReview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertReviewUser(Connection $db, Uuid $id, string $label): void
{
    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => 'organization-review-'.$label.'-'.$id->toRfc4122(),
        'email' => null,
        'password_hash' => null,
        'display_name' => $label,
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}

function insertReviewMedia(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    string $title,
    string $mediaType = 'image',
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'organization-review/'.$id->toRfc4122().'/source',
        'original_filename' => 'PRIVATE-'.$id->toRfc4122().'.jpg',
        'mime_type' => $mediaType === 'video' ? 'video/mp4' : 'image/jpeg',
        'media_type' => $mediaType,
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => $title,
        'description' => null,
        'captured_at' => '2026-09-28T10:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => '{}',
        'metadata_provenance' => '{}',
        'creator' => 'Review Fixture',
        'camera_make' => 'Fixture',
        'camera_model' => 'Fixture Model',
        'lens' => '35mm',
        'location_name' => 'Vienna',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

function evidence(): array
{
    return [
        new OrganizationEvidence(
            OrganizationEvidenceSource::Metadata,
            'Review fixture evidence.',
        ),
    ];
}

function smartPayload(string $title): OrganizationProposalPayload
{
    return OrganizationProposalPayload::fromArray(
        OrganizationProposalType::SmartCollection,
        [
            'version' => 1,
            'title' => $title,
            'description' => 'Accepted proposals must remain private.',
            'rule' => [
                'version' => 1,
                'op' => 'and',
                'rules' => [[
                    'field' => 'media_type',
                    'operator' => 'eq',
                    'value' => 'image',
                ]],
            ],
        ],
    );
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
$first = Uuid::v7();
$second = Uuid::v7();
$foreignCollection = Uuid::v7();
$publicCollection = Uuid::v7();

try {
    insertReviewUser($db, $owner, 'owner');
    insertReviewUser($db, $other, 'other');
    insertReviewMedia($db, $first, $owner, 'Review first');
    insertReviewMedia($db, $second, $owner, 'Review second');

    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('collections', [
        'id' => $foreignCollection->toRfc4122(),
        'owner_id' => $other->toRfc4122(),
        'parent_id' => null,
        'cover_media_id' => null,
        'slug' => null,
        'title' => 'Foreign collection',
        'description' => 'Must not be changed.',
        'visibility' => 'private',
        'position' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
    $db->insert('collections', [
        'id' => $publicCollection->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'parent_id' => null,
        'cover_media_id' => null,
        'slug' => null,
        'title' => 'Public review collection',
        'description' => 'Before reviewed presentation change.',
        'visibility' => 'public',
        'position' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'public_updated_at' => '2026-09-28T09:00:00+00:00',
        'deleted_at' => null,
    ]);

    $store = new DbalOrganizationProposalStore($db);
    $metadata = new DbalOrganizationMetadataSnapshotQuery($db);
    $application = new DbalOrganizationProposalApplication(
        $db,
        $store,
        $metadata,
        new SmartCollectionRuleCompiler(),
        new DbalSmartCollectionManagement($db),
        new DbalManualCollectionManagement($db),
        new DbalMediaTagManagement($db),
        new DbalOwnedPresentationManagement(
            $db,
            new DbalMediaAssetRepository($db),
            new DbalPublicPublicationTimelineStore($db),
        ),
    );

    $runId = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$first, $second],
    );
    $proposalId = $store->addProposal(
        $owner,
        $runId,
        smartPayload('Accepted Smart review'),
        'Both selected images currently match the reviewed rule.',
        [$first, $second],
        evidence(),
    );
    $store->markReadyForReview($owner, $runId);

    $beforeCollections = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM collections WHERE owner_id = :owner',
        ['owner' => $owner->toRfc4122()],
    );

    $applied = $application->apply($owner, $proposalId);
    requireOrganizationReview(
        $applied->resourceType === 'collection'
        && !$applied->alreadyApplied,
        'accepting Smart proposal returns one normal Collection outcome',
    );

    $collection = $db->fetchAssociative(
        'SELECT visibility, mode, title
         FROM collections
         WHERE id = :id',
        ['id' => $applied->resourceId->toRfc4122()],
    );
    requireOrganizationReview(
        $collection !== false
        && (string) $collection['visibility'] === 'private'
        && (string) $collection['mode'] === 'smart'
        && (string) $collection['title'] === 'Accepted Smart review',
        'accepted Smart proposal uses ordinary private Smart Collection state',
    );

    $persisted = $store->proposal($owner, $proposalId);
    requireOrganizationReview(
        $persisted->status === OrganizationProposalStatus::Applied
        && $persisted->appliedResourceType === 'collection'
        && $persisted->appliedResourceId?->equals($applied->resourceId),
        'applied proposal persists its idempotent resource outcome',
    );

    $retried = $application->apply($owner, $proposalId);
    requireOrganizationReview(
        $retried->alreadyApplied
        && $retried->resourceId->equals($applied->resourceId)
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collections WHERE owner_id = :owner',
            ['owner' => $owner->toRfc4122()],
        ) === $beforeCollections + 1,
        'duplicate acceptance reuses the original outcome without duplicates',
    );

    try {
        $application->apply($other, $proposalId);
        throw new RuntimeException(
            'Expected another user to be unable to apply the proposal.',
        );
    } catch (\Mediarama\Organization\Application\OrganizationProposalUnavailableException) {
        echo "OK proposal acceptance is requester-private".PHP_EOL;
    }

    $staleRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$first, $second],
    );
    $staleProposal = $store->addProposal(
        $owner,
        $staleRun,
        smartPayload('Stale Smart review'),
        'This reviewed rule will become stale.',
        [$first, $second],
        evidence(),
    );
    $store->markReadyForReview($owner, $staleRun);

    $db->update(
        'media_assets',
        ['media_type' => 'video', 'mime_type' => 'video/mp4'],
        ['id' => $second->toRfc4122()],
    );

    $collectionsBeforeStale = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM collections WHERE owner_id = :owner',
        ['owner' => $owner->toRfc4122()],
    );
    try {
        $application->apply($owner, $staleProposal);
        throw new RuntimeException(
            'Expected changed Smart membership to invalidate the proposal.',
        );
    } catch (OrganizationProposalStaleException) {
        echo "OK stale Smart membership fails closed".PHP_EOL;
    }
    requireOrganizationReview(
        $store->proposal($owner, $staleProposal)->status
            === OrganizationProposalStatus::Invalidated
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collections WHERE owner_id = :owner',
            ['owner' => $owner->toRfc4122()],
        ) === $collectionsBeforeStale,
        'stale proposal records invalidation without partial Collection creation',
    );

    $db->update(
        'media_assets',
        ['media_type' => 'image', 'mime_type' => 'image/jpeg'],
        ['id' => $second->toRfc4122()],
    );

    $reviewRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$first],
    );
    $reviewProposal = $store->addProposal(
        $owner,
        $reviewRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ReviewBucket,
            [
                'version' => 1,
                'title' => 'Needs review',
                'description' => 'Explicit manual review bucket.',
            ],
        ),
        'Keep this MediaAsset in a private manual review bucket.',
        [$first],
        evidence(),
    );
    $store->markReadyForReview($owner, $reviewRun);
    $reviewResult = $application->apply($owner, $reviewProposal);
    requireOrganizationReview(
        $reviewResult->resourceType === 'collection'
        && (string) $db->fetchOne(
            'SELECT mode FROM collections WHERE id = :id',
            ['id' => $reviewResult->resourceId->toRfc4122()],
        ) === 'manual'
        && (string) $db->fetchOne(
            'SELECT visibility FROM collections WHERE id = :id',
            ['id' => $reviewResult->resourceId->toRfc4122()],
        ) === 'private'
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
            ['id' => $reviewResult->resourceId->toRfc4122()],
        ) === 1,
        'review bucket acceptance creates a private manual Collection through the normal boundary',
    );

    $tagName = 'Review tag '.$owner->toRfc4122();
    $tagRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$first],
    );
    $tagProposal = $store->addProposal(
        $owner,
        $tagRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Tag,
            ['version' => 1, 'name' => $tagName],
        ),
        'Apply an explicit normalized tag.',
        [$first],
        evidence(),
    );
    $store->markReadyForReview($owner, $tagRun);
    $tagResult = $application->apply($owner, $tagProposal);
    requireOrganizationReview(
        $tagResult->resourceType === 'tag'
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM media_tags WHERE media_id = :media AND tag_id = :tag',
            [
                'media' => $first->toRfc4122(),
                'tag' => $tagResult->resourceId->toRfc4122(),
            ],
        ) === 1,
        'tag proposal acceptance uses normalized tag membership',
    );

    $accentedName = 'Café '.$owner->toRfc4122();
    $plainName = 'Cafe '.$owner->toRfc4122();
    $collisionResults = [];
    foreach ([$accentedName, $plainName] as $collisionName) {
        $collisionRun = $store->createRun(
            $owner,
            OrganizationProducer::metadata(),
            [$first],
        );
        $collisionProposal = $store->addProposal(
            $owner,
            $collisionRun,
            OrganizationProposalPayload::fromArray(
                OrganizationProposalType::Tag,
                ['version' => 1, 'name' => $collisionName],
            ),
            'Verify semantically distinct names never alias through one ASCII slug.',
            [$first],
            evidence(),
        );
        $store->markReadyForReview($owner, $collisionRun);
        $collisionResults[] = $application->apply(
            $owner,
            $collisionProposal,
        );
    }

    $collisionSlugs = $db->fetchFirstColumn(
        'SELECT slug
         FROM tags
         WHERE id IN (:a, :b)
         ORDER BY slug ASC',
        [
            'a' => $collisionResults[0]->resourceId->toRfc4122(),
            'b' => $collisionResults[1]->resourceId->toRfc4122(),
        ],
    );
    requireOrganizationReview(
        !$collisionResults[0]->resourceId->equals(
            $collisionResults[1]->resourceId,
        )
        && count(array_unique($collisionSlugs)) === 2,
        'distinct tag names with the same transliterated base slug remain distinct',
    );

    $publicUpdateRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$first],
    );
    $publicUpdateProposal = $store->addProposal(
        $owner,
        $publicUpdateRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::TitleDescription,
            [
                'version' => 1,
                'target_type' => 'collection',
                'target_id' => $publicCollection->toRfc4122(),
                'title' => 'Public review collection updated',
                'description' => null,
            ],
        ),
        'A reviewed public presentation edit must advance the truthful public-update timeline.',
        [$first],
        evidence(),
    );
    $store->markReadyForReview($owner, $publicUpdateRun);
    $application->apply($owner, $publicUpdateProposal);
    $publicUpdate = $db->fetchAssociative(
        'SELECT title, public_updated_at
         FROM collections
         WHERE id = :collection',
        ['collection' => $publicCollection->toRfc4122()],
    );
    requireOrganizationReview(
        $publicUpdate !== false
        && (string) $publicUpdate['title'] === 'Public review collection updated'
        && $publicUpdate['public_updated_at'] !== null
        && new DateTimeImmutable((string) $publicUpdate['public_updated_at'])
            > new DateTimeImmutable('2026-09-28T09:00:00+00:00'),
        'accepted public presentation edit advances public_updated_at',
    );

    $failureRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$first],
    );
    $failureProposal = $store->addProposal(
        $owner,
        $failureRun,
        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::TitleDescription,
            [
                'version' => 1,
                'target_type' => 'collection',
                'target_id' => $foreignCollection->toRfc4122(),
                'title' => 'Unauthorized change',
                'description' => null,
            ],
        ),
        'This target is deliberately not owned by the requester.',
        [$first],
        evidence(),
    );
    $store->markReadyForReview($owner, $failureRun);

    try {
        $application->apply($owner, $failureProposal);
        throw new RuntimeException(
            'Expected an unauthorized mutation to fail.',
        );
    } catch (DomainException) {
        echo "OK accepted proposal revalidates mutation ownership".PHP_EOL;
    }
    requireOrganizationReview(
        $store->proposal($owner, $failureProposal)->status
            === OrganizationProposalStatus::PendingReview
        && (string) $db->fetchOne(
            'SELECT title FROM collections WHERE id = :id',
            ['id' => $foreignCollection->toRfc4122()],
        ) === 'Foreign collection',
        'failed mutation rolls back without marking proposal applied or changing target',
    );

    $draftRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$second],
    );
    $draftProposal = $store->addProposal(
        $owner,
        $draftRun,
        smartPayload('Not reviewable yet'),
        'A draft run must not accept review actions.',
        [$second],
        evidence(),
    );
    try {
        $store->reject($owner, $draftProposal);
        throw new RuntimeException(
            'Expected draft-run proposal rejection to fail.',
        );
    } catch (DomainException) {
        echo "OK draft run rejects premature review action".PHP_EOL;
    }
    requireOrganizationReview(
        $store->proposal($owner, $draftProposal)->status
            === OrganizationProposalStatus::PendingReview,
        'premature review action leaves proposal pending and non-mutating',
    );

    $rejectRun = $store->createRun(
        $owner,
        OrganizationProducer::metadata(),
        [$second],
    );
    $rejectProposal = $store->addProposal(
        $owner,
        $rejectRun,
        smartPayload('Rejected Smart review'),
        'Rejection must stay non-mutating.',
        [$second],
        evidence(),
    );
    $store->markReadyForReview($owner, $rejectRun);
    $beforeReject = (int) $db->fetchOne('SELECT COUNT(*) FROM collections');
    $store->reject($owner, $rejectProposal);
    requireOrganizationReview(
        $store->proposal($owner, $rejectProposal)->status
            === OrganizationProposalStatus::Rejected
        && (int) $db->fetchOne('SELECT COUNT(*) FROM collections') === $beforeReject,
        'rejection remains explicitly non-mutating',
    );

    echo "Organization proposal application integration checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM organization_runs WHERE requester_id IN (:owner, :other)',
        [
            'owner' => $owner->toRfc4122(),
            'other' => $other->toRfc4122(),
        ],
    );
    $db->executeStatement(
        'DELETE FROM collections WHERE owner_id IN (:owner, :other)',
        [
            'owner' => $owner->toRfc4122(),
            'other' => $other->toRfc4122(),
        ],
    );
    $db->executeStatement(
        "DELETE FROM tags
         WHERE name LIKE 'Review tag %'
            OR name LIKE 'Café %'
            OR name LIKE 'Cafe %'"
    );
    $db->delete('media_assets', ['id' => $first->toRfc4122()]);
    $db->delete('media_assets', ['id' => $second->toRfc4122()]);
    $db->delete('users', ['id' => $owner->toRfc4122()]);
    $db->delete('users', ['id' => $other->toRfc4122()]);
    $db->close();
}
