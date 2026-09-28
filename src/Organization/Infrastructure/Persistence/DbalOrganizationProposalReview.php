<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\SmartCollectionManagement;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Organization\Application\OrganizationProposalApplyResult;
use Mediarama\Organization\Application\OrganizationProposalReview;
use Mediarama\Organization\Application\OrganizationProposalUnavailableException;
use Mediarama\Organization\Domain\OrganizationAppliedEntityType;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Mediarama\Publishing\Application\PublicPublicationTimelineStore;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationProposalReview implements OrganizationProposalReview
{
    private const MAX_BATCH = 100;

    public function __construct(
        private Connection $connection,
        private SmartCollectionManagement $smartCollections,
        private SmartCollectionRuleCompiler $smartRules,
        private PublicPublicationTimelineStore $timeline,
    ) {
    }

    public function edit(
        Uuid $requesterId,
        Uuid $proposalId,
        OrganizationProposalPayload $payload,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $proposalId,
            $payload,
        ): void {
            $row = $this->lockProposal(
                $connection,
                $requesterId,
                $proposalId,
            );

            $this->requirePendingReview($row);
            $currentType = OrganizationProposalType::from(
                (string) $row['proposal_type'],
            );
            if ($payload->type !== $currentType) {
                throw new \InvalidArgumentException(
                    'Organization proposal type cannot be changed during review.',
                );
            }

            $current = $this->decodePayload($row['payload']);
            $this->assertEditableShape(
                $currentType,
                $current,
                $payload->payload(),
            );
            $this->assertPayloadMediaConsistency(
                $connection,
                $proposalId,
                $payload,
            );

            $connection->executeStatement(
                <<<'SQL'
UPDATE organization_proposals
SET payload = CAST(:payload AS jsonb),
    updated_at = CURRENT_TIMESTAMP
WHERE id = :proposal
SQL,
                [
                    'payload' => $payload->toJson(),
                    'proposal' => $proposalId->toRfc4122(),
                ],
            );
        });
    }

    public function accept(
        Uuid $requesterId,
        Uuid $proposalId,
    ): OrganizationProposalApplyResult {
        $results = $this->acceptMany(
            $requesterId,
            [$proposalId],
        );

        return $results[0];
    }

    public function acceptMany(
        Uuid $requesterId,
        array $proposalIds,
    ): array {
        $proposalIds = $this->proposalIds($proposalIds);

        return $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $proposalIds,
        ): array {
            $results = [];

            foreach ($proposalIds as $proposalId) {
                $row = $this->lockProposal(
                    $connection,
                    $requesterId,
                    $proposalId,
                );
                $status = OrganizationProposalStatus::from(
                    (string) $row['status'],
                );

                if ($status === OrganizationProposalStatus::Applied) {
                    if (
                        $row['applied_entity_type'] === null
                        || $row['applied_entity_id'] === null
                    ) {
                        throw new \RuntimeException(
                            'Applied organization proposal is missing its result identity.',
                        );
                    }

                    $results[] = new OrganizationProposalApplyResult(
                        $proposalId,
                        OrganizationAppliedEntityType::from(
                            (string) $row['applied_entity_type'],
                        ),
                        Uuid::fromString(
                            (string) $row['applied_entity_id'],
                        ),
                        true,
                    );

                    continue;
                }

                $this->requirePendingReview($row);

                $payload = OrganizationProposalPayload::fromArray(
                    OrganizationProposalType::from(
                        (string) $row['proposal_type'],
                    ),
                    $this->decodePayload($row['payload']),
                );
                $mediaIds = $this->proposalMedia(
                    $connection,
                    $proposalId,
                );

                $this->assertOwnedCurrentMedia(
                    $connection,
                    $requesterId,
                    $mediaIds,
                );

                if ($payload->type === OrganizationProposalType::SmartCollection) {
                    $this->assertSmartRuleStillMatchesReviewScope(
                        $connection,
                        $requesterId,
                        $payload,
                        $mediaIds,
                    );
                }

                [$entityType, $entityId] = $this->apply(
                    $connection,
                    $requesterId,
                    $payload,
                    $mediaIds,
                );

                $connection->executeStatement(
                    <<<'SQL'
UPDATE organization_proposals
SET status = :status,
    applied_entity_type = :entity_type,
    applied_entity_id = :entity_id,
    reviewed_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :proposal
SQL,
                    [
                        'status' => OrganizationProposalStatus::Applied->value,
                        'entity_type' => $entityType->value,
                        'entity_id' => $entityId->toRfc4122(),
                        'proposal' => $proposalId->toRfc4122(),
                    ],
                );

                $results[] = new OrganizationProposalApplyResult(
                    $proposalId,
                    $entityType,
                    $entityId,
                );
            }

            return $results;
        });
    }

    public function rejectMany(
        Uuid $requesterId,
        array $proposalIds,
    ): void {
        $proposalIds = $this->proposalIds($proposalIds);

        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $proposalIds,
        ): void {
            foreach ($proposalIds as $proposalId) {
                $row = $this->lockProposal(
                    $connection,
                    $requesterId,
                    $proposalId,
                );
                $status = OrganizationProposalStatus::from(
                    (string) $row['status'],
                );

                if ($status === OrganizationProposalStatus::Rejected) {
                    continue;
                }

                if (
                    (string) $row['run_status']
                    !== OrganizationRunStatus::ReadyForReview->value
                ) {
                    throw new \DomainException(
                        'Organization run is not ready for proposal review.',
                    );
                }

                if ($status !== OrganizationProposalStatus::PendingReview) {
                    throw new \DomainException(
                        'Only pending organization proposals can be rejected.',
                    );
                }

                $connection->executeStatement(
                    <<<'SQL'
UPDATE organization_proposals
SET status = :status,
    reviewed_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :proposal
SQL,
                    [
                        'status' => OrganizationProposalStatus::Rejected->value,
                        'proposal' => $proposalId->toRfc4122(),
                    ],
                );
            }
        });
    }

    /**
     * @return array{0:OrganizationAppliedEntityType,1:Uuid}
     * @param list<Uuid> $mediaIds
     */
    private function apply(
        Connection $connection,
        Uuid $requesterId,
        OrganizationProposalPayload $proposal,
        array $mediaIds,
    ): array {
        $payload = $proposal->payload();

        return match ($proposal->type) {
            OrganizationProposalType::SmartCollection =>
                $this->applySmartCollection(
                    $requesterId,
                    $payload,
                ),
            OrganizationProposalType::ManualCollection,
            OrganizationProposalType::ReviewBucket =>
                $this->applyManualCollection(
                    $connection,
                    $requesterId,
                    $payload,
                    $mediaIds,
                ),
            OrganizationProposalType::Tag =>
                $this->applyTag(
                    $connection,
                    $requesterId,
                    $payload,
                    $mediaIds,
                ),
            OrganizationProposalType::TitleDescription =>
                $this->applyTitleDescription(
                    $connection,
                    $requesterId,
                    $payload,
                ),
            OrganizationProposalType::Cover =>
                $this->applyCover(
                    $connection,
                    $requesterId,
                    $payload,
                ),
        };
    }

    /** @param array<string,mixed> $payload
     *  @return array{0:OrganizationAppliedEntityType,1:Uuid}
     */
    private function applySmartCollection(
        Uuid $requesterId,
        array $payload,
    ): array {
        $id = $this->smartCollections->create(
            $requesterId,
            (string) $payload['title'],
            $payload['description'] !== null
                ? (string) $payload['description']
                : null,
            SmartCollectionRule::fromArray(
                (array) $payload['rule'],
            ),
        );

        return [OrganizationAppliedEntityType::Collection, $id];
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<Uuid> $mediaIds
     * @return array{0:OrganizationAppliedEntityType,1:Uuid}
     */
    private function applyManualCollection(
        Connection $connection,
        Uuid $requesterId,
        array $payload,
        array $mediaIds,
    ): array {
        $id = Uuid::v7();
        $now = (new DateTimeImmutable())->format(DATE_ATOM);

        $connection->insert('collections', [
            'id' => $id->toRfc4122(),
            'owner_id' => $requesterId->toRfc4122(),
            'parent_id' => null,
            'cover_media_id' => null,
            'slug' => null,
            'title' => (string) $payload['title'],
            'description' => $payload['description'] !== null
                ? (string) $payload['description']
                : null,
            'visibility' => 'private',
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
            'password_protected' => false,
            'password_hash' => null,
            'password_hint' => null,
            'password_reset_required' => false,
            'mode' => 'manual',
            'smart_rule' => null,
            'search_index_policy' => 'noindex',
        ]);

        foreach ($mediaIds as $position => $mediaId) {
            $connection->insert('collection_media', [
                'collection_id' => $id->toRfc4122(),
                'media_id' => $mediaId->toRfc4122(),
                'position' => $position,
                'added_by' => $requesterId->toRfc4122(),
                'created_at' => $now,
            ]);
        }

        return [OrganizationAppliedEntityType::Collection, $id];
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<Uuid> $mediaIds
     * @return array{0:OrganizationAppliedEntityType,1:Uuid}
     */
    private function applyTag(
        Connection $connection,
        Uuid $requesterId,
        array $payload,
        array $mediaIds,
    ): array {
        $name = (string) $payload['name'];
        $existing = $connection->fetchOne(
            'SELECT id
             FROM tags
             WHERE LOWER(name) = LOWER(:name)
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE',
            ['name' => $name],
        );

        if ($existing === false) {
            $id = Uuid::v7();
            $slugger = new AsciiSlugger();
            $baseSlug = strtolower(
                (string) $slugger->slug($name, '-'),
            );
            if ($baseSlug === '') {
                $baseSlug = 'tag';
            }

            $slug = $baseSlug;
            if ($connection->fetchOne(
                'SELECT 1 FROM tags WHERE slug = :slug',
                ['slug' => $slug],
            ) !== false) {
                $slug = $baseSlug.'-'.substr(
                    hash('sha256', $name),
                    0,
                    10,
                );
            }

            $now = (new DateTimeImmutable())->format(DATE_ATOM);
            $connection->insert('tags', [
                'id' => $id->toRfc4122(),
                'slug' => $slug,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $id = Uuid::fromString((string) $existing);
        }

        foreach ($mediaIds as $mediaId) {
            $connection->executeStatement(
                <<<'SQL'
INSERT INTO media_tags (media_id, tag_id, source)
VALUES (:media, :tag, 'manual')
ON CONFLICT (media_id, tag_id) DO NOTHING
SQL,
                [
                    'media' => $mediaId->toRfc4122(),
                    'tag' => $id->toRfc4122(),
                ],
            );
        }

        return [OrganizationAppliedEntityType::Tag, $id];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:OrganizationAppliedEntityType,1:Uuid}
     */
    private function applyTitleDescription(
        Connection $connection,
        Uuid $requesterId,
        array $payload,
    ): array {
        $targetId = Uuid::fromString(
            (string) $payload['target_id'],
        );
        $title = $payload['title'] !== null
            ? (string) $payload['title']
            : null;
        $description = $payload['description'] !== null
            ? (string) $payload['description']
            : null;
        $now = new DateTimeImmutable();

        if ((string) $payload['target_type'] === 'media') {
            $row = $connection->fetchAssociative(
                'SELECT moderation_state
                 FROM media_assets
                 WHERE id = :media
                   AND owner_id = :owner
                   AND deleted_at IS NULL
                   AND processing_state = \'ready\'
                 FOR UPDATE',
                [
                    'media' => $targetId->toRfc4122(),
                    'owner' => $requesterId->toRfc4122(),
                ],
            );
            if ($row === false) {
                throw new \DomainException(
                    'Media proposal target changed or is no longer editable.',
                );
            }

            $connection->executeStatement(
                'UPDATE media_assets
                 SET title = COALESCE(:title, title),
                     description = COALESCE(:description, description),
                     updated_at = :updated
                 WHERE id = :media',
                [
                    'title' => $title,
                    'description' => $description,
                    'updated' => $now->format(DATE_ATOM),
                    'media' => $targetId->toRfc4122(),
                ],
            );

            if (
                (string) $row['moderation_state'] === 'published'
                && (bool) $connection->fetchOne(
                    'SELECT EXISTS (
                        SELECT 1
                        FROM collection_media membership
                        JOIN effective_public_collections visible
                          ON visible.collection_id = membership.collection_id
                        WHERE membership.media_id = :media
                    )',
                    ['media' => $targetId->toRfc4122()],
                )
            ) {
                $this->timeline->touchMediaPublicContent(
                    $targetId,
                    $now,
                );
            }

            return [OrganizationAppliedEntityType::Media, $targetId];
        }

        $row = $connection->fetchAssociative(
            'SELECT visibility
             FROM collections
             WHERE id = :collection
               AND owner_id = :owner
               AND deleted_at IS NULL
             FOR UPDATE',
            [
                'collection' => $targetId->toRfc4122(),
                'owner' => $requesterId->toRfc4122(),
            ],
        );
        if ($row === false) {
            throw new \DomainException(
                'Collection proposal target changed or is no longer editable.',
            );
        }

        $connection->executeStatement(
            'UPDATE collections
             SET title = COALESCE(:title, title),
                 description = COALESCE(:description, description),
                 updated_at = :updated
             WHERE id = :collection',
            [
                'title' => $title,
                'description' => $description,
                'updated' => $now->format(DATE_ATOM),
                'collection' => $targetId->toRfc4122(),
            ],
        );

        if ((string) $row['visibility'] === 'public') {
            $this->timeline->touchCollectionPublicContent(
                $targetId,
                $now,
            );
        }

        return [OrganizationAppliedEntityType::Collection, $targetId];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:OrganizationAppliedEntityType,1:Uuid}
     */
    private function applyCover(
        Connection $connection,
        Uuid $requesterId,
        array $payload,
    ): array {
        $collectionId = Uuid::fromString(
            (string) $payload['collection_id'],
        );
        $mediaId = Uuid::fromString(
            (string) $payload['media_id'],
        );
        $collection = $connection->fetchAssociative(
            'SELECT visibility
             FROM collections
             WHERE id = :collection
               AND owner_id = :owner
               AND deleted_at IS NULL
             FOR UPDATE',
            [
                'collection' => $collectionId->toRfc4122(),
                'owner' => $requesterId->toRfc4122(),
            ],
        );
        if ($collection === false) {
            throw new \DomainException(
                'Cover proposal Collection changed or is no longer editable.',
            );
        }

        if (!(bool) $connection->fetchOne(
            'SELECT EXISTS (
                SELECT 1
                FROM media_assets
                WHERE id = :media
                  AND owner_id = :owner
                  AND deleted_at IS NULL
                  AND processing_state = \'ready\'
            )',
            [
                'media' => $mediaId->toRfc4122(),
                'owner' => $requesterId->toRfc4122(),
            ],
        )) {
            throw new \DomainException(
                'Cover proposal MediaAsset changed or is no longer editable.',
            );
        }

        $now = new DateTimeImmutable();
        $connection->executeStatement(
            'UPDATE collections
             SET cover_media_id = :media,
                 updated_at = :updated
             WHERE id = :collection',
            [
                'media' => $mediaId->toRfc4122(),
                'updated' => $now->format(DATE_ATOM),
                'collection' => $collectionId->toRfc4122(),
            ],
        );

        if ((string) $collection['visibility'] === 'public') {
            $this->timeline->touchCollectionPublicContent(
                $collectionId,
                $now,
            );
        }

        return [OrganizationAppliedEntityType::Collection, $collectionId];
    }

    /** @return array<string,mixed> */
    private function lockProposal(
        Connection $connection,
        Uuid $requesterId,
        Uuid $proposalId,
    ): array {
        $row = $connection->fetchAssociative(
            <<<'SQL'
SELECT
    p.id,
    p.run_id,
    p.proposal_type,
    p.status,
    p.payload,
    p.applied_entity_type,
    p.applied_entity_id,
    r.status AS run_status
FROM organization_proposals p
JOIN organization_runs r ON r.id = p.run_id
WHERE p.id = :proposal
  AND r.requester_id = :requester
FOR UPDATE OF p, r
SQL,
            [
                'proposal' => $proposalId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );

        if ($row === false) {
            throw new OrganizationProposalUnavailableException(
                'Organization proposal is unavailable.',
            );
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function requirePendingReview(array $row): void
    {
        if (
            (string) $row['run_status']
            !== OrganizationRunStatus::ReadyForReview->value
        ) {
            throw new \DomainException(
                'Organization run is not ready for proposal review.',
            );
        }

        if (
            (string) $row['status']
            !== OrganizationProposalStatus::PendingReview->value
        ) {
            throw new \DomainException(
                'Only a pending organization proposal can be changed or accepted.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode(
                $payload,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        if (!is_array($payload)) {
            throw new \RuntimeException(
                'Persisted organization proposal payload is invalid.',
            );
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $updated
     */
    private function assertEditableShape(
        OrganizationProposalType $type,
        array $current,
        array $updated,
    ): void {
        $unchanged = match ($type) {
            OrganizationProposalType::SmartCollection =>
                ($current['rule'] ?? null) === ($updated['rule'] ?? null),
            OrganizationProposalType::ManualCollection,
            OrganizationProposalType::ReviewBucket,
            OrganizationProposalType::Tag => true,
            OrganizationProposalType::TitleDescription =>
                ($current['target_type'] ?? null) === ($updated['target_type'] ?? null)
                && ($current['target_id'] ?? null) === ($updated['target_id'] ?? null),
            OrganizationProposalType::Cover =>
                ($current['collection_id'] ?? null) === ($updated['collection_id'] ?? null),
        };

        if (!$unchanged) {
            throw new \InvalidArgumentException(
                'Review editing cannot change the proposal target or Smart rule; rerun analysis for a structural change.',
            );
        }
    }

    private function assertPayloadMediaConsistency(
        Connection $connection,
        Uuid $proposalId,
        OrganizationProposalPayload $proposal,
    ): void {
        $payload = $proposal->payload();

        if ($proposal->type === OrganizationProposalType::Cover) {
            $this->assertProposalMediaContains(
                $connection,
                $proposalId,
                Uuid::fromString((string) $payload['media_id']),
            );
        }

        if (
            $proposal->type === OrganizationProposalType::TitleDescription
            && ($payload['target_type'] ?? null) === 'media'
        ) {
            $this->assertProposalMediaContains(
                $connection,
                $proposalId,
                Uuid::fromString((string) $payload['target_id']),
            );
        }
    }

    private function assertProposalMediaContains(
        Connection $connection,
        Uuid $proposalId,
        Uuid $mediaId,
    ): void {
        if (!(bool) $connection->fetchOne(
            'SELECT EXISTS (
                SELECT 1
                FROM organization_proposal_media
                WHERE proposal_id = :proposal
                  AND media_id = :media
            )',
            [
                'proposal' => $proposalId->toRfc4122(),
                'media' => $mediaId->toRfc4122(),
            ],
        )) {
            throw new \InvalidArgumentException(
                'Organization proposal target MediaAsset is outside its reviewed scope.',
            );
        }
    }

    /** @return list<Uuid> */
    private function proposalMedia(
        Connection $connection,
        Uuid $proposalId,
    ): array {
        return array_map(
            static fn (mixed $id): Uuid =>
                Uuid::fromString((string) $id),
            $connection->fetchFirstColumn(
                'SELECT media_id
                 FROM organization_proposal_media
                 WHERE proposal_id = :proposal
                 ORDER BY position ASC, media_id ASC',
                ['proposal' => $proposalId->toRfc4122()],
            ),
        );
    }

    /** @param list<Uuid> $mediaIds */
    private function assertOwnedCurrentMedia(
        Connection $connection,
        Uuid $requesterId,
        array $mediaIds,
    ): void {
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM media_assets
             WHERE id IN (:media_ids)
               AND owner_id = :owner
               AND deleted_at IS NULL
               AND processing_state = \'ready\'',
            [
                'media_ids' => array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $mediaIds,
                ),
                'owner' => $requesterId->toRfc4122(),
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        if ($count !== count($mediaIds)) {
            throw new \DomainException(
                'Organization proposal scope changed or now contains media that the requester cannot safely mutate.',
            );
        }
    }

    /**
     * @param list<Uuid> $affectedMediaIds
     */
    private function assertSmartRuleStillMatchesReviewScope(
        Connection $connection,
        Uuid $requesterId,
        OrganizationProposalPayload $proposal,
        array $affectedMediaIds,
    ): void {
        $payload = $proposal->payload();
        $rule = SmartCollectionRule::fromArray(
            (array) $payload['rule'],
        );
        $predicate = $this->smartRules->compile($rule);

        $current = $connection->fetchFirstColumn(
            'SELECT m.id
             FROM media_assets m
             WHERE m.owner_id = :owner
               AND m.deleted_at IS NULL
               AND m.processing_state = \'ready\'
               AND '.$predicate->sql.'
             ORDER BY m.id ASC',
            [
                'owner' => $requesterId->toRfc4122(),
                ...$predicate->parameters,
            ],
        );

        $current = array_map(
            static fn (mixed $id): string => (string) $id,
            $current,
        );
        $expected = array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $affectedMediaIds,
        );
        sort($current);
        sort($expected);

        if ($current !== $expected) {
            throw new \DomainException(
                'Smart Collection proposal is stale or its rule now matches media outside the reviewed scope; rerun analysis before accepting it.',
            );
        }
    }

    /**
     * @param list<mixed> $proposalIds
     * @return list<Uuid>
     */
    private function proposalIds(array $proposalIds): array
    {
        if ($proposalIds === [] || count($proposalIds) > self::MAX_BATCH) {
            throw new \InvalidArgumentException(
                'Organization proposal batch must contain 1-100 proposals.',
            );
        }

        $unique = [];
        foreach ($proposalIds as $proposalId) {
            if (!$proposalId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Organization proposal batch must contain UUID objects only.',
                );
            }
            $unique[$proposalId->toRfc4122()] = $proposalId;
        }

        ksort($unique);

        return array_values($unique);
    }
}
