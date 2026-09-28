<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Mediarama\Organization\Application\OrganizationAiCoordinator;
use Mediarama\Organization\Application\OrganizationAiProvider;
use Mediarama\Organization\Application\OrganizationAiProviderExecutionException;
use Mediarama\Organization\Application\OrganizationAiProviderRegistry;
use Mediarama\Organization\Application\OrganizationAiRequest;
use Mediarama\Organization\Application\OrganizationAiRequestSummary;
use Mediarama\Organization\Application\OrganizationProposalCandidate;
use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Organization\Domain\OrganizationAiPreflightStatus;
use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationAiPreflightStore;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationAiPresentationAssetReader;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationMetadataSnapshotQuery;
use Mediarama\Organization\Infrastructure\Persistence\DbalOrganizationProposalStore;
use Symfony\Component\Uid\Uuid;

function requireAiBoundary(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertAiUser(Connection $db, Uuid $id, string $label): void
{
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => 'organization-ai-'.$label.'-'.$id->toRfc4122(),
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

function insertAiMedia(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    string $title,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'PRIVATE_SOURCE_STORAGE_SENTINEL/'.$id->toRfc4122(),
        'original_filename' => 'PRIVATE_ORIGINAL_FILENAME_SENTINEL.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => 1600,
        'height' => 1200,
        'duration_ms' => null,
        'title' => $title,
        'description' => null,
        'captured_at' => '2026-09-28T10:00:00+00:00',
        'processing_state' => 'ready',
        'moderation_state' => 'published',
        'metadata' => json_encode([
            'PRIVATE_RAW_METADATA_SENTINEL' => 'secret',
        ], JSON_THROW_ON_ERROR),
        'metadata_provenance' => json_encode([
            'creator' => 'PRIVATE_PROVENANCE_SENTINEL',
        ], JSON_THROW_ON_ERROR),
        'creator' => 'PRIVATE CREATOR SENTINEL',
        'copyright' => null,
        'camera_make' => 'Nikon',
        'camera_model' => 'Z 8',
        'lens' => '35mm',
        'iso' => 100,
        'aperture' => null,
        'exposure_time' => null,
        'focal_length' => null,
        'latitude' => 48.123456,
        'longitude' => 16.654321,
        'location_name' => 'PRIVATE COARSE LOCATION SENTINEL',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

final class IntegrationOrganizationAiProvider implements OrganizationAiProvider
{
    public int $proposeCalls = 0;
    public int $estimateCalls = 0;
    public bool $fail = false;
    public ?OrganizationAiRequest $lastRequest = null;
    public ?string $presentationBytes = null;
    public bool $unapprovedPresentationStayedHidden = false;

    private string $credential = 'PROVIDER_CREDENTIAL_SENTINEL';
    private string $rawResponse = 'RAW_PROVIDER_RESPONSE_SENTINEL';

    public function descriptor(): OrganizationAiProviderDescriptor
    {
        return new OrganizationAiProviderDescriptor(
            'integration-external',
            OrganizationProducerKind::AiExternal,
            'Integration AI Provider',
            'integration-model',
            '2026-09-28',
            [
                OrganizationAiCapability::TextReasoning,
                OrganizationAiCapability::ImageUnderstanding,
                OrganizationAiCapability::BatchAnalysis,
            ],
            'External test provider receives only the explicitly approved Mediarama input.',
            'Integration fixture retention note.',
        );
    }

    public function estimateCost(
        OrganizationAiRequestSummary $summary,
    ): ?string {
        ++$this->estimateCalls;

        return sprintf(
            'Estimated maximum EUR 0.02 for %d media items.',
            $summary->mediaCount,
        );
    }

    public function propose(
        OrganizationAiRequest $request,
    ): array {
        ++$this->proposeCalls;

        if ($this->fail) {
            throw new RuntimeException(
                $this->rawResponse.' '.$this->credential,
            );
        }

        $this->lastRequest = $request;
        $first = $request->media[0];
        $presentation = $request->presentations->presentation($first->id);
        if ($presentation === null) {
            throw new RuntimeException(
                'Expected approved presentation derivative.',
            );
        }

        $this->presentationBytes = $presentation->bytes;

        if (isset($request->media[1])) {
            $this->unapprovedPresentationStayedHidden =
                $request->presentations->presentation(
                    $request->media[1]->id,
                ) === null;
        }

        return [
            new OrganizationProposalCandidate(
                OrganizationProposalPayload::fromArray(
                    OrganizationProposalType::Tag,
                    [
                        'version' => 1,
                        'name' => 'AI suggested tag',
                    ],
                ),
                'The approved provider input supports a review-only tag proposal.',
                [$first->id],
                [
                    new OrganizationEvidence(
                        OrganizationEvidenceSource::Inference,
                        'External inference proposed a tag from the approved bounded input.',
                    ),
                ],
            ),
        ];
    }
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$mediaRoot = (string) getenv('MEDIA_STORAGE_PATH');
if ($mediaRoot === '') {
    throw new RuntimeException('MEDIA_STORAGE_PATH is required.');
}

$storage = new LocalMediaStorage($mediaRoot);
$requester = Uuid::v7();
$other = Uuid::v7();
$mediaWithPresentation = Uuid::v7();
$mediaMetadataOnly = Uuid::v7();
$unauthorizedMedia = Uuid::v7();
$presentationStorage = new StorageObjectId(
    'media',
    'organization-ai/'.$mediaWithPresentation->toRfc4122().'/preview.png',
);

try {
    insertAiUser($db, $requester, 'requester');
    insertAiUser($db, $other, 'other');
    insertAiMedia(
        $db,
        $mediaWithPresentation,
        $requester,
        'AI fixture with presentation',
    );
    insertAiMedia(
        $db,
        $mediaMetadataOnly,
        $requester,
        'AI fixture metadata only',
    );
    insertAiMedia(
        $db,
        $unauthorizedMedia,
        $other,
        'AI fixture inaccessible',
    );

    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zk1sAAAAASUVORK5CYII=',
        true,
    );
    if ($png === false) {
        throw new RuntimeException('Unable to build presentation fixture.');
    }

    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Unable to create presentation stream.');
    }
    fwrite($stream, $png);
    rewind($stream);
    $stored = $storage->write(
        $presentationStorage,
        $stream,
        'image/png',
    );
    fclose($stream);

    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('media_derivatives', [
        'id' => Uuid::v7()->toRfc4122(),
        'media_id' => $mediaWithPresentation->toRfc4122(),
        'kind' => 'image',
        'profile' => 'preview',
        'processing_version' => 1,
        'storage_disk' => $presentationStorage->disk,
        'storage_key' => $presentationStorage->key,
        'mime_type' => 'image/png',
        'byte_size' => $stored->byteSize,
        'width' => 1,
        'height' => 1,
        'duration_ms' => null,
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $provider = new IntegrationOrganizationAiProvider();
    $registry = new OrganizationAiProviderRegistry([$provider]);
    $proposalStore = new DbalOrganizationProposalStore($db);
    $preflightStore = new DbalOrganizationAiPreflightStore($db);
    $snapshot = new DbalOrganizationMetadataSnapshotQuery($db);
    $presentations = new DbalOrganizationAiPresentationAssetReader(
        $db,
        $storage,
    );
    $coordinator = new OrganizationAiCoordinator(
        $registry,
        $snapshot,
        $presentations,
        $preflightStore,
        $proposalStore,
    );

    requireAiBoundary(
        (new OrganizationAiProviderRegistry([]))->available() === [],
        'AI provider configuration is optional',
    );

    $preflightCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM organization_ai_preflights',
    );

    try {
        $coordinator->prepare(
            $requester,
            'integration-external',
            [OrganizationAiCapability::TextReasoning],
            OrganizationAiInputMode::MetadataOnly,
            [$mediaWithPresentation, $unauthorizedMedia],
        );
        throw new RuntimeException(
            'Expected inaccessible media to fail preflight.',
        );
    } catch (InvalidArgumentException) {
        echo "OK unauthorized media cannot enter AI preflight scope".PHP_EOL;
    }

    requireAiBoundary(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM organization_ai_preflights',
        ) === $preflightCount,
        'invalid AI scope leaves no durable preflight',
    );

    try {
        $coordinator->prepare(
            $requester,
            'integration-external',
            [OrganizationAiCapability::Embeddings],
            OrganizationAiInputMode::MetadataOnly,
            [$mediaWithPresentation],
        );
        throw new RuntimeException(
            'Expected unsupported provider capability to fail.',
        );
    } catch (InvalidArgumentException) {
        echo "OK unsupported AI capability fails closed".PHP_EOL;
    }

    try {
        $coordinator->prepare(
            $requester,
            'integration-external',
            [OrganizationAiCapability::ImageUnderstanding],
            OrganizationAiInputMode::MetadataOnly,
            [$mediaWithPresentation],
        );
        throw new RuntimeException(
            'Expected image understanding without presentation to fail.',
        );
    } catch (InvalidArgumentException) {
        echo "OK image understanding requires presentation derivative approval".PHP_EOL;
    }

    requireAiBoundary(
        $provider->proposeCalls === 0,
        'provider inference has not run during validation or preflight',
    );

    $normalBefore = [
        'collections' => (int) $db->fetchOne('SELECT COUNT(*) FROM collections'),
        'tags' => (int) $db->fetchOne('SELECT COUNT(*) FROM tags'),
    ];

    $preflight = $coordinator->prepare(
        $requester,
        'integration-external',
        [
            OrganizationAiCapability::TextReasoning,
            OrganizationAiCapability::ImageUnderstanding,
        ],
        OrganizationAiInputMode::MetadataAndPresentation,
        [$mediaWithPresentation, $mediaMetadataOnly],
    );

    requireAiBoundary(
        $preflight->status === OrganizationAiPreflightStatus::PendingApproval
        && $preflight->mediaCount === 2
        && $preflight->presentationMediaCount === 1
        && $preflight->mediaTypeCounts === ['image' => 2],
        'preflight truthfully reports approved media and presentation scope',
    );
    requireAiBoundary(
        !$preflight->sendsOriginals()
        && in_array('exact_gps', $preflight->excludedFields, true)
        && in_array('source_storage', $preflight->excludedFields, true)
        && in_array('original_filename', $preflight->excludedFields, true)
        && in_array('raw_metadata', $preflight->excludedFields, true)
        && in_array('metadata_provenance', $preflight->excludedFields, true)
        && in_array('creator', $preflight->excludedFields, true)
        && in_array('coarse_location_name', $preflight->excludedFields, true),
        'preflight names excluded sensitive fields and never approves originals',
    );
    requireAiBoundary(
        $preflight->costEstimate === 'Estimated maximum EUR 0.02 for 2 media items.'
        && $provider->estimateCalls === 1
        && $provider->proposeCalls === 0,
        'cost preview is local and provider inference is still gated',
    );

    $preflightJson = (string) $db->fetchOne(
        'SELECT row_to_json(p)::text
         FROM organization_ai_preflights p
         WHERE p.id = :id',
        ['id' => $preflight->id->toRfc4122()],
    );
    foreach ([
        'PRIVATE_SOURCE_STORAGE_SENTINEL',
        'PRIVATE_ORIGINAL_FILENAME_SENTINEL',
        'PRIVATE_RAW_METADATA_SENTINEL',
        'PRIVATE_PROVENANCE_SENTINEL',
        'PROVIDER_CREDENTIAL_SENTINEL',
        '48.123456',
        '16.654321',
    ] as $forbidden) {
        requireAiBoundary(
            !str_contains($preflightJson, $forbidden),
            'preflight persistence excludes '.$forbidden,
        );
    }

    try {
        $coordinator->execute(
            $requester,
            $preflight->id,
        );
        throw new RuntimeException(
            'Expected unapproved preflight execution to fail.',
        );
    } catch (DomainException) {
        echo "OK provider execution cannot start before deliberate approval".PHP_EOL;
    }

    requireAiBoundary(
        $provider->proposeCalls === 0
        && $preflightStore->get(
            $requester,
            $preflight->id,
        )->status === OrganizationAiPreflightStatus::PendingApproval,
        'unapproved execution is non-mutating and never calls provider inference',
    );

    $approved = $coordinator->approve(
        $requester,
        $preflight->id,
    );
    requireAiBoundary(
        $approved->status === OrganizationAiPreflightStatus::Approved
        && $approved->approvedAt !== null,
        'preflight records deliberate approval before execution',
    );

    $runId = $coordinator->execute(
        $requester,
        $preflight->id,
    );

    requireAiBoundary(
        $provider->proposeCalls === 1
        && $provider->lastRequest !== null,
        'approved preflight permits exactly one provider inference call',
    );

    $providerInput = $provider->lastRequest->media[0];
    requireAiBoundary(
        $providerInput->creator === null
        && $providerInput->locationName === null
        && !property_exists($providerInput, 'latitude')
        && !property_exists($providerInput, 'longitude')
        && !property_exists($providerInput, 'storageKey')
        && !property_exists($providerInput, 'originalFilename')
        && !property_exists($providerInput, 'metadata')
        && !property_exists($providerInput, 'metadataProvenance'),
        'provider DTO excludes exact GPS, source identity, raw metadata and opted-out identity fields',
    );
    requireAiBoundary(
        $provider->presentationBytes === $png
        && $provider->unapprovedPresentationStayedHidden,
        'provider receives only the explicitly approved bounded presentation derivative',
    );

    $run = $proposalStore->run(
        $requester,
        $runId,
    );
    requireAiBoundary(
        $run->producer->kind === OrganizationProducerKind::AiExternal
        && $run->producer->providerName === 'Integration AI Provider'
        && $run->producer->modelName === 'integration-model'
        && $run->producer->modelVersion === '2026-09-28'
        && $run->status === OrganizationRunStatus::ReadyForReview,
        'provider/model/version audit identity is recorded on review-only run',
    );

    $proposals = $proposalStore->proposals(
        $requester,
        $runId,
    );
    requireAiBoundary(
        count($proposals) === 1
        && $proposals[0]->status === OrganizationProposalStatus::PendingReview
        && $proposals[0]->payload->type === OrganizationProposalType::Tag
        && $proposals[0]->evidence[0]->source === OrganizationEvidenceSource::Inference,
        'provider result becomes a Mediarama-owned pending review proposal',
    );

    $completed = $preflightStore->get(
        $requester,
        $preflight->id,
    );
    requireAiBoundary(
        $completed->status === OrganizationAiPreflightStatus::Completed
        && $completed->runId?->equals($runId) === true
        && $completed->completedAt !== null,
        'preflight links to completed organization run only after successful persistence',
    );

    requireAiBoundary(
        (int) $db->fetchOne('SELECT COUNT(*) FROM collections') === $normalBefore['collections']
        && (int) $db->fetchOne('SELECT COUNT(*) FROM tags') === $normalBefore['tags'],
        'successful AI proposal generation does not mutate normal library organization',
    );

    $provider->fail = true;
    $failedPreflight = $coordinator->prepare(
        $requester,
        'integration-external',
        [OrganizationAiCapability::TextReasoning],
        OrganizationAiInputMode::MetadataOnly,
        [$mediaWithPresentation],
    );
    $coordinator->approve(
        $requester,
        $failedPreflight->id,
    );

    $runsBeforeFailure = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM organization_runs WHERE requester_id = :requester',
        ['requester' => $requester->toRfc4122()],
    );
    $proposalsBeforeFailure = (int) $db->fetchOne(
        'SELECT COUNT(*)
         FROM organization_proposals p
         JOIN organization_runs r ON r.id = p.run_id
         WHERE r.requester_id = :requester',
        ['requester' => $requester->toRfc4122()],
    );

    try {
        $coordinator->execute(
            $requester,
            $failedPreflight->id,
        );
        throw new RuntimeException(
            'Expected provider failure to be sanitized.',
        );
    } catch (OrganizationAiProviderExecutionException $exception) {
        requireAiBoundary(
            !str_contains(
                $exception->getMessage(),
                'RAW_PROVIDER_RESPONSE_SENTINEL',
            )
            && !str_contains(
                $exception->getMessage(),
                'PROVIDER_CREDENTIAL_SENTINEL',
            ),
            'provider exception is sanitized at the application boundary',
        );
    }

    $failed = $preflightStore->get(
        $requester,
        $failedPreflight->id,
    );
    requireAiBoundary(
        $failed->status === OrganizationAiPreflightStatus::Failed
        && $failed->failureCode === 'provider_failure',
        'provider failure records only a stable sanitized failure code',
    );
    requireAiBoundary(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM organization_runs WHERE requester_id = :requester',
            ['requester' => $requester->toRfc4122()],
        ) === $runsBeforeFailure
        && (int) $db->fetchOne(
            'SELECT COUNT(*)
             FROM organization_proposals p
             JOIN organization_runs r ON r.id = p.run_id
             WHERE r.requester_id = :requester',
            ['requester' => $requester->toRfc4122()],
        ) === $proposalsBeforeFailure,
        'provider failure creates no partial proposal run',
    );
    requireAiBoundary(
        (int) $db->fetchOne('SELECT COUNT(*) FROM collections') === $normalBefore['collections']
        && (int) $db->fetchOne('SELECT COUNT(*) FROM tags') === $normalBefore['tags'],
        'provider failure leaves normal library state unchanged',
    );

    $durableAiState = (string) $db->fetchOne(
        <<<'SQL'
SELECT COALESCE(string_agg(state, ' '), '')
FROM (
    SELECT row_to_json(p)::text AS state
    FROM organization_ai_preflights p
    WHERE p.requester_id = :requester
    UNION ALL
    SELECT row_to_json(r)::text AS state
    FROM organization_runs r
    WHERE r.requester_id = :requester
    UNION ALL
    SELECT row_to_json(p)::text AS state
    FROM organization_proposals p
    JOIN organization_runs r ON r.id = p.run_id
    WHERE r.requester_id = :requester
) durable
SQL,
        ['requester' => $requester->toRfc4122()],
    );

    foreach ([
        'RAW_PROVIDER_RESPONSE_SENTINEL',
        'PROVIDER_CREDENTIAL_SENTINEL',
        'PRIVATE_SOURCE_STORAGE_SENTINEL',
        'PRIVATE_ORIGINAL_FILENAME_SENTINEL',
        'PRIVATE_RAW_METADATA_SENTINEL',
        'PRIVATE_PROVENANCE_SENTINEL',
        '48.123456',
        '16.654321',
    ] as $forbidden) {
        requireAiBoundary(
            !str_contains($durableAiState, $forbidden),
            'durable AI state excludes '.$forbidden,
        );
    }

    echo "Organization AI provider/preflight privacy boundary checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM organization_ai_preflights WHERE requester_id = :requester',
        ['requester' => $requester->toRfc4122()],
    );
    $db->executeStatement(
        'DELETE FROM organization_runs WHERE requester_id = :requester',
        ['requester' => $requester->toRfc4122()],
    );

    foreach ([
        $mediaWithPresentation,
        $mediaMetadataOnly,
        $unauthorizedMedia,
    ] as $mediaId) {
        $db->delete('media_assets', [
            'id' => $mediaId->toRfc4122(),
        ]);
    }

    foreach ([$requester, $other] as $userId) {
        $db->delete('users', [
            'id' => $userId->toRfc4122(),
        ]);
    }

    if ($storage->exists($presentationStorage)) {
        $storage->delete($presentationStorage);
    }

    $db->close();
}
