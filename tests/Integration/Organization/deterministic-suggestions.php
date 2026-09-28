<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Organization\Application\DeterministicOrganizationAnalyzer;
use Mediarama\Organization\Application\DeterministicOrganizationPlanner;
use Mediarama\Organization\Application\OrganizationProposalResult;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationMetadataSnapshotQuery;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalStore;
use Symfony\Component\Uid\Uuid;

function requireDeterministic(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertDeterministicUser(
    Connection $db,
    Uuid $id,
    string $label,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => 'organization-'.$label.'-'.$id->toRfc4122(),
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

function insertDeterministicMedia(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    int $index,
    string $mediaType,
    string $capturedAt,
    string $cameraModel,
    string $lens,
    string $location,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'PRIVATE_STORAGE_SENTINEL/'.$id->toRfc4122(),
        'original_filename' => 'PRIVATE-SOURCE-'.$index.'.jpg',
        'mime_type' => $mediaType === 'video' ? 'video/mp4' : 'image/jpeg',
        'media_type' => $mediaType,
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => $mediaType === 'video' ? 1920 : 1600,
        'height' => $mediaType === 'video' ? 1080 : 1200,
        'duration_ms' => $mediaType === 'video' ? 1000 : null,
        'title' => 'Organization media '.$index,
        'description' => null,
        'captured_at' => $capturedAt,
        'processing_state' => 'ready',
        'moderation_state' => 'published',
        'metadata' => json_encode([
            'PRIVATE_RAW_METADATA_SENTINEL' => 'secret',
        ], JSON_THROW_ON_ERROR),
        'metadata_provenance' => '{}',
        'creator' => 'Fixture Creator',
        'copyright' => null,
        'camera_make' => 'Fixture Camera',
        'camera_model' => $cameraModel,
        'lens' => $lens,
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

/**
 * @param list<OrganizationProposalResult> $proposals
 * @return list<string>
 */
function deterministicSignatures(array $proposals): array
{
    $signatures = array_map(
        static function (OrganizationProposalResult $proposal): string {
            $media = array_map(
                static fn (Uuid $id): string => $id->toRfc4122(),
                $proposal->affectedMediaIds,
            );

            return implode('|', [
                $proposal->payload->type->value,
                json_encode(
                    $proposal->payload->payload(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                implode(',', $media),
                $proposal->rationale,
            ]);
        },
        $proposals,
    );

    sort($signatures);

    return $signatures;
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
$inaccessible = Uuid::v7();
/** @var list<Uuid> $mediaIds */
$mediaIds = [];
$architectureTag = Uuid::v7();
$videoTag = Uuid::v7();

try {
    insertDeterministicUser($db, $requester, 'deterministic-requester');
    insertDeterministicUser($db, $other, 'deterministic-other');

    for ($index = 1; $index <= 12; ++$index) {
        $id = Uuid::v7();
        $mediaIds[] = $id;

        $vienna = $index <= 10;
        insertDeterministicMedia(
            $db,
            $id,
            $requester,
            $index,
            mediaType: $index >= 6 && $index <= 10 ? 'video' : 'image',
            capturedAt: $vienna
                ? sprintf('2026-09-%02dT10:00:00+00:00', $index)
                : sprintf('2026-10-%02dT10:00:00+00:00', $index - 10),
            cameraModel: $index <= 7
                ? 'Nikon Z 8'
                : ($index <= 10 ? 'Other Camera' : 'Isolated Camera'),
            lens: $index <= 8
                ? '35mm'
                : ($index <= 10 ? '50mm' : '85mm'),
            location: $vienna ? 'Vienna' : 'Graz',
        );
    }

    insertDeterministicMedia(
        $db,
        $inaccessible,
        $other,
        99,
        'image',
        '2026-09-15T10:00:00+00:00',
        'PRIVATE OTHER CAMERA',
        'PRIVATE OTHER LENS',
        'PRIVATE OTHER LOCATION',
    );

    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('tags', [
        'id' => $architectureTag->toRfc4122(),
        'slug' => 'organization-architecture-'.$architectureTag->toRfc4122(),
        'name' => 'Architecture',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->insert('tags', [
        'id' => $videoTag->toRfc4122(),
        'slug' => 'organization-video-'.$videoTag->toRfc4122(),
        'name' => 'Video',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach (array_slice($mediaIds, 0, 5) as $mediaId) {
        $db->insert('media_tags', [
            'media_id' => $mediaId->toRfc4122(),
            'tag_id' => $architectureTag->toRfc4122(),
            'source' => 'manual',
        ]);
    }
    foreach (array_slice($mediaIds, 5, 5) as $mediaId) {
        $db->insert('media_tags', [
            'media_id' => $mediaId->toRfc4122(),
            'tag_id' => $videoTag->toRfc4122(),
            'source' => 'manual',
        ]);
    }

    foreach (array_slice($mediaIds, 0, 6) as $index => $mediaId) {
        $db->insert('ratings', [
            'user_id' => $requester->toRfc4122(),
            'media_id' => $mediaId->toRfc4122(),
            'value' => $index % 2 === 0 ? 5 : 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $store = new DbalOrganizationProposalStore($db);
    $analyzer = new DeterministicOrganizationAnalyzer(
        new DbalOrganizationMetadataSnapshotQuery($db),
        new DeterministicOrganizationPlanner(),
        $store,
    );

    $runCountBeforeUnauthorized = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM organization_runs WHERE requester_id = :requester',
        ['requester' => $requester->toRfc4122()],
    );
    try {
        $analyzer->analyze(
            $requester,
            [$mediaIds[0], $inaccessible],
        );
        throw new RuntimeException(
            'Expected inaccessible analysis scope to fail.',
        );
    } catch (InvalidArgumentException) {
        echo "OK deterministic analysis applies authorization before run creation".PHP_EOL;
    }
    requireDeterministic(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM organization_runs WHERE requester_id = :requester',
            ['requester' => $requester->toRfc4122()],
        ) === $runCountBeforeUnauthorized,
        'failed authorization leaves no durable organization run',
    );

    $normalBefore = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
        'collection_media' => (int) $db->fetchOne('SELECT COUNT(*) FROM collection_media'),
    ];

    $firstRunId = $analyzer->analyze($requester, $mediaIds);
    $firstRun = $store->run($requester, $firstRunId);
    $firstProposals = $store->proposals($requester, $firstRunId);

    requireDeterministic(
        $firstRun->producer->kind === OrganizationProducerKind::Metadata
        && $firstRun->producer->providerName === null
        && $firstRun->status === OrganizationRunStatus::ReadyForReview,
        'metadata-only analyzer needs no AI provider and produces review-only state',
    );
    requireDeterministic(
        count($firstProposals) >= 4
        && count($firstProposals) <= DeterministicOrganizationPlanner::MAX_PROPOSALS,
        'explicit support and proposal limits produce a bounded useful set',
    );

    $serializedPayloads = json_encode(
        array_map(
            static fn (OrganizationProposalResult $proposal): array =>
                $proposal->payload->payload(),
            $firstProposals,
        ),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    requireDeterministic(
        str_contains($serializedPayloads, 'Vienna')
        && str_contains($serializedPayloads, 'Nikon Z 8')
        && str_contains($serializedPayloads, 'Architecture'),
        'deterministic proposals use normalized location, camera and tag signals',
    );
    requireDeterministic(
        !str_contains($serializedPayloads, 'Isolated Camera'),
        'sub-threshold metadata groups do not create clutter proposals',
    );

    foreach ($firstProposals as $proposal) {
        if ($proposal->payload->type !== OrganizationProposalType::SmartCollection) {
            continue;
        }

        $payload = $proposal->payload->payload();
        SmartCollectionRule::fromArray($payload['rule']);
    }
    echo "OK generated Smart suggestions validate through the normal Smart rule grammar".PHP_EOL;

    $secondRunId = $analyzer->analyze($requester, $mediaIds);
    $secondProposals = $store->proposals($requester, $secondRunId);
    requireDeterministic(
        deterministicSignatures($firstProposals)
        === deterministicSignatures($secondProposals),
        'same authorized metadata snapshot produces deterministic proposal semantics',
    );

    $smallRunId = $analyzer->analyze(
        $requester,
        array_slice($mediaIds, 10, 2),
    );
    requireDeterministic(
        $store->run($requester, $smallRunId)->status
            === OrganizationRunStatus::NoSuggestions
        && $store->proposals($requester, $smallRunId) === [],
        'below-threshold analysis completes explicitly without low-value suggestions',
    );

    $normalAfter = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
        'collection_media' => (int) $db->fetchOne('SELECT COUNT(*) FROM collection_media'),
    ];
    requireDeterministic(
        $normalBefore === $normalAfter,
        'deterministic analysis never mutates normal Collection/tag organization',
    );

    $persisted = (string) $db->fetchOne(
        <<<'SQL'
SELECT COALESCE(
    string_agg(
        p.payload::text
        || ' ' || p.rationale
        || ' ' || COALESCE(e.summary, ''),
        ' '
    ),
    ''
)
FROM organization_runs r
LEFT JOIN organization_proposals p ON p.run_id = r.id
LEFT JOIN organization_proposal_evidence e ON e.proposal_id = p.id
WHERE r.requester_id = :requester
SQL,
        ['requester' => $requester->toRfc4122()],
    );

    foreach ([
        'PRIVATE_STORAGE_SENTINEL',
        'PRIVATE_RAW_METADATA_SENTINEL',
        '48.123456',
        '16.654321',
        'PRIVATE-SOURCE-',
        'PRIVATE OTHER LOCATION',
    ] as $forbidden) {
        requireDeterministic(
            !str_contains($persisted, $forbidden),
            'deterministic proposal persistence excludes private sentinel '.$forbidden,
        );
    }

    echo "Deterministic organization suggestion integration checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM organization_runs WHERE requester_id IN (:requester, :other)',
        [
            'requester' => $requester->toRfc4122(),
            'other' => $other->toRfc4122(),
        ],
    );

    foreach ($mediaIds as $mediaId) {
        $db->delete('media_assets', ['id' => $mediaId->toRfc4122()]);
    }
    $db->delete('media_assets', ['id' => $inaccessible->toRfc4122()]);

    $db->delete('tags', ['id' => $architectureTag->toRfc4122()]);
    $db->delete('tags', ['id' => $videoTag->toRfc4122()]);

    $db->delete('users', ['id' => $requester->toRfc4122()]);
    $db->delete('users', ['id' => $other->toRfc4122()]);
    $db->close();
}
