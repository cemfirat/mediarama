<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Organization\Application\OrganizationProposalUnavailableException;
use Mediarama\Organization\Application\OrganizationRunUnavailableException;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalStore;
use Symfony\Component\Uid\Uuid;

function requireOrganization(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertOrganizationUser(
    Connection $db,
    Uuid $id,
    string $username,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => $username,
        'email' => null,
        'password_hash' => null,
        'display_name' => $username,
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}

function insertOrganizationMedia(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    string $title,
    string $storageSentinel,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => $storageSentinel,
        'original_filename' => 'PRIVATE-SOURCE-'.$id->toRfc4122().'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => $title,
        'description' => null,
        'captured_at' => '2026-09-20T10:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => json_encode([
            'PRIVATE_RAW_METADATA_SENTINEL' => 'secret',
        ], JSON_THROW_ON_ERROR),
        'metadata_provenance' => '{}',
        'creator' => 'Proposal Fixture',
        'camera_make' => 'Camera',
        'camera_model' => 'Model',
        'lens' => '35mm',
        'latitude' => 48.123456,
        'longitude' => 16.654321,
        'location_name' => 'Vienna',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$requester = Uuid::v7();
$other = Uuid::v7();
$visibleMedia = Uuid::v7();
$inaccessibleMedia = Uuid::v7();
$baselineCollection = Uuid::v7();
$baselineTag = Uuid::v7();

try {
    insertOrganizationUser($db, $requester, 'organization-requester-'.$requester->toRfc4122());
    insertOrganizationUser($db, $other, 'organization-other-'.$other->toRfc4122());

    insertOrganizationMedia(
        $db,
        $visibleMedia,
        $requester,
        'Organization visible fixture',
        'PRIVATE_STORAGE_SENTINEL/'.$visibleMedia->toRfc4122(),
    );
    insertOrganizationMedia(
        $db,
        $inaccessibleMedia,
        $other,
        'Organization inaccessible fixture',
        'PRIVATE_OTHER_STORAGE_SENTINEL/'.$inaccessibleMedia->toRfc4122(),
    );

    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('collections', [
        'id' => $baselineCollection->toRfc4122(),
        'owner_id' => $requester->toRfc4122(),
        'parent_id' => null,
        'cover_media_id' => null,
        'slug' => null,
        'title' => 'Existing normal Collection',
        'description' => null,
        'visibility' => 'private',
        'position' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
    $db->insert('tags', [
        'id' => $baselineTag->toRfc4122(),
        'slug' => 'existing-tag-'.$baselineTag->toRfc4122(),
        'name' => 'Existing normal tag',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $store = new DbalOrganizationProposalStore($db);

    try {
        $store->createRun(
            $requester,
            OrganizationProducer::metadata(),
            [$inaccessibleMedia],
        );
        throw new RuntimeException(
            'Expected inaccessible analysis scope to fail.',
        );
    } catch (InvalidArgumentException) {
        echo "OK organization scope authorization fails closed".PHP_EOL;
    }

    $runId = $store->createRun(
        $requester,
        OrganizationProducer::metadata(),
        [$visibleMedia],
    );

    $run = $store->run($requester, $runId);
    requireOrganization(
        $run->status === OrganizationRunStatus::Draft
        && $run->mediaCount === 1
        && $run->producer->providerName === null,
        'metadata-only run needs no AI provider configuration',
    );

    try {
        $store->run($other, $runId);
        throw new RuntimeException(
            'Expected another user to be unable to read the run.',
        );
    } catch (OrganizationRunUnavailableException) {
        echo "OK organization run is requester-private".PHP_EOL;
    }

    $payload = OrganizationProposalPayload::fromArray(
        OrganizationProposalType::SmartCollection,
        [
            'version' => 1,
            'title' => 'September images',
            'description' => 'Deterministic metadata proposal.',
            'rule' => [
                'version' => 1,
                'op' => 'and',
                'rules' => [[
                    'field' => 'captured_at',
                    'operator' => 'gte',
                    'value' => '2026-09-01T00:00:00+00:00',
                ]],
            ],
        ],
    );

    $proposalId = $store->addProposal(
        $requester,
        $runId,
        $payload,
        'The selected MediaAsset was captured during September 2026.',
        [$visibleMedia],
        [
            new OrganizationEvidence(
                OrganizationEvidenceSource::Metadata,
                'Capture date falls within September 2026.',
            ),
        ],
    );

    $store->markReadyForReview($requester, $runId);
    requireOrganization(
        $store->run($requester, $runId)->status
            === OrganizationRunStatus::ReadyForReview,
        'run becomes reviewable only after proposals exist',
    );

    $proposals = $store->proposals($requester, $runId);
    requireOrganization(
        count($proposals) === 1
        && $proposals[0]->id->equals($proposalId)
        && $proposals[0]->status === OrganizationProposalStatus::PendingReview
        && $proposals[0]->payload->type === OrganizationProposalType::SmartCollection
        && count($proposals[0]->affectedMediaIds) === 1
        && count($proposals[0]->evidence) === 1
        && $proposals[0]->evidence[0]->source === OrganizationEvidenceSource::Metadata,
        'review model reconstitutes validated proposal, media and evidence',
    );

    $normalBefore = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
        'collection_media' => (int) $db->fetchOne('SELECT COUNT(*) FROM collection_media'),
    ];

    try {
        $store->reject($other, $proposalId);
        throw new RuntimeException(
            'Expected another user to be unable to reject the proposal.',
        );
    } catch (OrganizationProposalUnavailableException) {
        echo "OK proposal review is requester-private".PHP_EOL;
    }

    $store->reject($requester, $proposalId);
    $store->reject($requester, $proposalId);

    $rejected = $store->proposals($requester, $runId)[0];
    requireOrganization(
        $rejected->status === OrganizationProposalStatus::Rejected
        && $rejected->reviewedAt !== null,
        'rejection is explicit and idempotent',
    );

    $normalAfter = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
        'collection_media' => (int) $db->fetchOne('SELECT COUNT(*) FROM collection_media'),
    ];
    requireOrganization(
        $normalBefore === $normalAfter,
        'rejected proposal leaves normal library organization unchanged',
    );

    $persistedProposalText = (string) $db->fetchOne(
        <<<'SQL'
SELECT
    p.payload::text
    || ' ' || p.rationale
    || ' ' || COALESCE(string_agg(e.summary, ' '), '')
FROM organization_proposals p
LEFT JOIN organization_proposal_evidence e
  ON e.proposal_id = p.id
WHERE p.id = :proposal
GROUP BY p.id, p.payload, p.rationale
SQL,
        ['proposal' => $proposalId->toRfc4122()],
    );
    foreach ([
        'PRIVATE_STORAGE_SENTINEL',
        'PRIVATE_RAW_METADATA_SENTINEL',
        '48.123456',
        '16.654321',
        'PRIVATE-SOURCE-',
    ] as $forbidden) {
        requireOrganization(
            !str_contains($persistedProposalText, $forbidden),
            'proposal persistence excludes source/GPS sentinel '.$forbidden,
        );
    }

    $aiRunId = $store->createRun(
        $requester,
        OrganizationProducer::aiExternal(
            'provider-example',
            'vision-model',
            '2026-09',
        ),
        [$visibleMedia],
    );
    $aiRun = $store->run($requester, $aiRunId);
    requireOrganization(
        $aiRun->producer->providerName === 'provider-example'
        && $aiRun->producer->modelName === 'vision-model'
        && $aiRun->producer->modelVersion === '2026-09',
        'AI run records minimal provider/model/version audit identity only',
    );

    echo "Organization proposal foundation integration checks passed.".PHP_EOL;
} finally {
    $db->delete('collections', ['id' => $baselineCollection->toRfc4122()]);
    $db->delete('tags', ['id' => $baselineTag->toRfc4122()]);
    $db->delete('media_assets', ['id' => $visibleMedia->toRfc4122()]);
    $db->delete('media_assets', ['id' => $inaccessibleMedia->toRfc4122()]);
    $db->delete('users', ['id' => $requester->toRfc4122()]);
    $db->delete('users', ['id' => $other->toRfc4122()]);
    $db->close();
}
