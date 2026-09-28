<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\ManualCollectionManagement;
use Mediarama\Collection\Application\SmartCollectionManagement;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Application\MediaTagManagement;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Mediarama\Organization\Application\OrganizationMetadataSnapshotQuery;
use Mediarama\Organization\Application\OrganizationProposalApplication;
use Mediarama\Organization\Application\OrganizationProposalApplicationResult;
use Mediarama\Organization\Application\OrganizationProposalResult;
use Mediarama\Organization\Application\OrganizationProposalStaleException;
use Mediarama\Organization\Application\OrganizationProposalStore;
use Mediarama\Organization\Application\OrganizationProposalUnavailableException;
use Mediarama\Organization\Application\OwnedPresentationManagement;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationProposalApplication implements OrganizationProposalApplication
{
    public function __construct(
        private Connection $connection,
        private OrganizationProposalStore $store,
        private OrganizationMetadataSnapshotQuery $metadata,
        private SmartCollectionRuleCompiler $smartRules,
        private SmartCollectionManagement $smartCollections,
        private ManualCollectionManagement $manualCollections,
        private MediaTagManagement $tags,
        private OwnedPresentationManagement $presentation,
    ) {
    }

    public function apply(
        Uuid $requesterId,
        Uuid $proposalId,
    ): OrganizationProposalApplicationResult {
        $stale = false;

        $result = $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $proposalId,
            &$stale,
        ): ?OrganizationProposalApplicationResult {
            $locked = $connection->fetchAssociative(
                <<<'SQL'
SELECT
    p.status,
    p.applied_resource_type,
    p.applied_resource_id,
    r.status AS run_status
FROM organization_proposals p
JOIN organization_runs r ON r.id = p.run_id
WHERE p.id = :proposal
  AND r.requester_id = :requester
FOR UPDATE OF p
SQL,
                [
                    'proposal' => $proposalId->toRfc4122(),
                    'requester' => $requesterId->toRfc4122(),
                ],
            );

            if ($locked === false) {
                throw new OrganizationProposalUnavailableException(
                    'Organization proposal is unavailable.',
                );
            }

            $status = OrganizationProposalStatus::from(
                (string) $locked['status'],
            );

            if ($status === OrganizationProposalStatus::Applied) {
                if (
                    $locked['applied_resource_type'] === null
                    || $locked['applied_resource_id'] === null
                ) {
                    throw new \RuntimeException(
                        'Applied organization proposal has no persisted outcome.',
                    );
                }

                return new OrganizationProposalApplicationResult(
                    $proposalId,
                    (string) $locked['applied_resource_type'],
                    Uuid::fromString((string) $locked['applied_resource_id']),
                    true,
                );
            }

            if ($status !== OrganizationProposalStatus::PendingReview) {
                throw new \DomainException(
                    'Only a pending organization proposal can be accepted.',
                );
            }

            if (
                (string) $locked['run_status']
                !== OrganizationRunStatus::ReadyForReview->value
            ) {
                throw new \DomainException(
                    'Organization run is not ready for proposal acceptance.',
                );
            }

            $proposal = $this->store->proposal(
                $requesterId,
                $proposalId,
            );

            if (!$this->isFresh($requesterId, $proposal)) {
                $connection->executeStatement(
                    'UPDATE organization_proposals
                     SET status = :status,
                         reviewed_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :proposal',
                    [
                        'status' => OrganizationProposalStatus::Invalidated->value,
                        'proposal' => $proposalId->toRfc4122(),
                    ],
                );
                $stale = true;

                return null;
            }

            [$resourceType, $resourceId] = $this->applyProposal(
                $requesterId,
                $proposal,
            );

            $connection->executeStatement(
                'UPDATE organization_proposals
                 SET status = :status,
                     applied_resource_type = :resource_type,
                     applied_resource_id = :resource_id,
                     reviewed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :proposal',
                [
                    'status' => OrganizationProposalStatus::Applied->value,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId->toRfc4122(),
                    'proposal' => $proposalId->toRfc4122(),
                ],
            );

            return new OrganizationProposalApplicationResult(
                $proposalId,
                $resourceType,
                $resourceId,
                false,
            );
        });

        if ($stale) {
            throw new OrganizationProposalStaleException(
                'Organization proposal is stale and was invalidated. Regenerate or review the analysis again.',
            );
        }

        if ($result === null) {
            throw new \RuntimeException(
                'Organization proposal application produced no result.',
            );
        }

        return $result;
    }

    /** @return array{0:string,1:Uuid} */
    private function applyProposal(
        Uuid $requesterId,
        OrganizationProposalResult $proposal,
    ): array {
        $data = $proposal->payload->payload();

        return match ($proposal->payload->type) {
            OrganizationProposalType::SmartCollection => [
                'collection',
                $this->smartCollections->create(
                    $requesterId,
                    (string) $data['title'],
                    $data['description'] !== null
                        ? (string) $data['description']
                        : null,
                    SmartCollectionRule::fromArray($data['rule']),
                ),
            ],
            OrganizationProposalType::ManualCollection,
            OrganizationProposalType::ReviewBucket => [
                'collection',
                $this->manualCollections->createPrivate(
                    $requesterId,
                    (string) $data['title'],
                    $data['description'] !== null
                        ? (string) $data['description']
                        : null,
                    $proposal->affectedMediaIds,
                ),
            ],
            OrganizationProposalType::Tag => [
                'tag',
                $this->tags->tagOwnedMedia(
                    $requesterId,
                    (string) $data['name'],
                    $proposal->affectedMediaIds,
                ),
            ],
            OrganizationProposalType::TitleDescription => $this->applyPresentation(
                $requesterId,
                $data,
            ),
            OrganizationProposalType::Cover => $this->applyCover(
                $requesterId,
                $data,
            ),
        };
    }

    /** @param array<string,mixed> $data
     *  @return array{0:string,1:Uuid}
     */
    private function applyPresentation(
        Uuid $requesterId,
        array $data,
    ): array {
        $targetId = Uuid::fromString((string) $data['target_id']);
        $title = $data['title'] !== null ? (string) $data['title'] : null;
        $description = $data['description'] !== null
            ? (string) $data['description']
            : null;

        if ((string) $data['target_type'] === 'media') {
            $this->presentation->updateMedia(
                $requesterId,
                $targetId,
                $title,
                $description,
            );

            return ['media', $targetId];
        }

        $this->presentation->updateCollection(
            $requesterId,
            $targetId,
            $title,
            $description,
        );

        return ['collection', $targetId];
    }

    /** @param array<string,mixed> $data
     *  @return array{0:string,1:Uuid}
     */
    private function applyCover(
        Uuid $requesterId,
        array $data,
    ): array {
        $collectionId = Uuid::fromString((string) $data['collection_id']);
        $mediaId = Uuid::fromString((string) $data['media_id']);

        $this->presentation->setCollectionCover(
            $requesterId,
            $collectionId,
            $mediaId,
        );

        return ['collection', $collectionId];
    }

    private function isFresh(
        Uuid $requesterId,
        OrganizationProposalResult $proposal,
    ): bool {
        try {
            $this->metadata->snapshot(
                $requesterId,
                $proposal->affectedMediaIds,
            );
        } catch (\Throwable) {
            return false;
        }

        if ($proposal->payload->type !== OrganizationProposalType::SmartCollection) {
            return true;
        }

        $rule = SmartCollectionRule::fromArray(
            $proposal->payload->payload()['rule'],
        );
        $predicate = $this->smartRules->compile($rule);

        $rows = $this->connection->fetchFirstColumn(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT m.id
FROM organization_run_media rm
JOIN media_assets m ON m.id = rm.media_id
WHERE rm.run_id = :run
  AND '.AuthenticatedMediaAccessSql::predicate('m').'
  AND '.$predicate->sql.'
ORDER BY m.id ASC',
            [
                'run' => $proposal->runId->toRfc4122(),
                'user' => $requesterId->toRfc4122(),
                ...$predicate->parameters,
            ],
        );

        $current = array_map(
            static fn (mixed $id): string => (string) $id,
            $rows,
        );
        $expected = array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $proposal->affectedMediaIds,
        );
        sort($current);
        sort($expected);

        return $current === $expected;
    }
}
