<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Mediarama\Organization\Application\OrganizationProposalResult;
use Mediarama\Organization\Application\OrganizationProposalStore;
use Mediarama\Organization\Application\OrganizationProposalUnavailableException;
use Mediarama\Organization\Application\OrganizationRunResult;
use Mediarama\Organization\Application\OrganizationRunUnavailableException;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationProposalStore implements OrganizationProposalStore
{
    private const MAX_SCOPE_MEDIA = 50000;
    private const MAX_PROPOSAL_MEDIA = 50000;
    private const MAX_PROPOSALS_PER_RUN = 200;
    private const MAX_EVIDENCE_PER_PROPOSAL = 20;

    public function __construct(private Connection $connection)
    {
    }

    public function createRun(
        Uuid $requesterId,
        OrganizationProducer $producer,
        array $mediaIds,
    ): Uuid {
        $mediaIds = $this->normalizeMediaIds(
            $mediaIds,
            self::MAX_SCOPE_MEDIA,
            'organization run scope',
        );

        return $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $producer,
            $mediaIds,
        ): Uuid {
            $this->assertVisibleMedia(
                $connection,
                $requesterId,
                $mediaIds,
            );

            $runId = Uuid::v7();
            $now = (new DateTimeImmutable())->format(DATE_ATOM);

            $connection->insert('organization_runs', [
                'id' => $runId->toRfc4122(),
                'requester_id' => $requesterId->toRfc4122(),
                'producer_kind' => $producer->kind->value,
                'provider_name' => $producer->providerName,
                'model_name' => $producer->modelName,
                'model_version' => $producer->modelVersion,
                'status' => OrganizationRunStatus::Draft->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->insertRunMedia(
                $connection,
                $runId,
                $mediaIds,
            );

            return $runId;
        });
    }

    public function addProposal(
        Uuid $requesterId,
        Uuid $runId,
        OrganizationProposalPayload $payload,
        string $rationale,
        array $affectedMediaIds,
        array $evidence,
    ): Uuid {
        $rationale = $this->rationale($rationale);
        $affectedMediaIds = $this->normalizeMediaIds(
            $affectedMediaIds,
            self::MAX_PROPOSAL_MEDIA,
            'organization proposal affected media',
        );
        $evidence = $this->evidence($evidence);

        $this->assertPayloadMediaConsistency(
            $payload,
            $affectedMediaIds,
        );

        return $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $runId,
            $payload,
            $rationale,
            $affectedMediaIds,
            $evidence,
        ): Uuid {
            $this->lockDraftRun(
                $connection,
                $requesterId,
                $runId,
            );

            $proposalCount = (int) $connection->fetchOne(
                'SELECT COUNT(*)
                 FROM organization_proposals
                 WHERE run_id = :run',
                ['run' => $runId->toRfc4122()],
            );

            if ($proposalCount >= self::MAX_PROPOSALS_PER_RUN) {
                throw new \InvalidArgumentException(
                    'Organization run contains too many proposals.',
                );
            }

            $this->assertRunMedia(
                $connection,
                $runId,
                $affectedMediaIds,
            );

            $proposalId = Uuid::v7();
            $now = (new DateTimeImmutable())->format(DATE_ATOM);

            $connection->executeStatement(
                <<<'SQL'
INSERT INTO organization_proposals (
    id,
    run_id,
    proposal_type,
    status,
    payload,
    rationale,
    reviewed_at,
    created_at,
    updated_at
) VALUES (
    :id,
    :run_id,
    :proposal_type,
    :status,
    CAST(:payload AS jsonb),
    :rationale,
    NULL,
    :created_at,
    :updated_at
)
SQL,
                [
                    'id' => $proposalId->toRfc4122(),
                    'run_id' => $runId->toRfc4122(),
                    'proposal_type' => $payload->type->value,
                    'status' => OrganizationProposalStatus::PendingReview->value,
                    'payload' => $payload->toJson(),
                    'rationale' => $rationale,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $this->insertProposalMedia(
                $connection,
                $proposalId,
                $runId,
                $affectedMediaIds,
            );
            $this->insertEvidence(
                $connection,
                $proposalId,
                $evidence,
            );

            return $proposalId;
        });
    }

    public function markReadyForReview(
        Uuid $requesterId,
        Uuid $runId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $runId,
        ): void {
            $this->lockDraftRun(
                $connection,
                $requesterId,
                $runId,
            );

            $count = (int) $connection->fetchOne(
                'SELECT COUNT(*)
                 FROM organization_proposals
                 WHERE run_id = :run',
                ['run' => $runId->toRfc4122()],
            );

            if ($count < 1) {
                throw new \InvalidArgumentException(
                    'Organization run cannot enter review without proposals.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_runs
                 SET status = :status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :run',
                [
                    'status' => OrganizationRunStatus::ReadyForReview->value,
                    'run' => $runId->toRfc4122(),
                ],
            );
        });
    }

    public function markNoSuggestions(
        Uuid $requesterId,
        Uuid $runId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $runId,
        ): void {
            $this->lockDraftRun(
                $connection,
                $requesterId,
                $runId,
            );

            $count = (int) $connection->fetchOne(
                'SELECT COUNT(*)
                 FROM organization_proposals
                 WHERE run_id = :run',
                ['run' => $runId->toRfc4122()],
            );

            if ($count !== 0) {
                throw new \DomainException(
                    'Organization run with proposals cannot be marked as no-suggestions.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_runs
                 SET status = :status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :run',
                [
                    'status' => OrganizationRunStatus::NoSuggestions->value,
                    'run' => $runId->toRfc4122(),
                ],
            );
        });
    }

    public function markFailed(
        Uuid $requesterId,
        Uuid $runId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $runId,
        ): void {
            $status = $connection->fetchOne(
                <<<'SQL'
SELECT status
FROM organization_runs
WHERE id = :run
  AND requester_id = :requester
FOR UPDATE
SQL,
                [
                    'run' => $runId->toRfc4122(),
                    'requester' => $requesterId->toRfc4122(),
                ],
            );

            if ($status === false) {
                throw new OrganizationRunUnavailableException(
                    'Organization run is unavailable.',
                );
            }

            if ((string) $status === OrganizationRunStatus::Failed->value) {
                return;
            }

            if ((string) $status !== OrganizationRunStatus::Draft->value) {
                throw new \DomainException(
                    'Only a draft organization run can fail during proposal generation.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_runs
                 SET status = :status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :run',
                [
                    'status' => OrganizationRunStatus::Failed->value,
                    'run' => $runId->toRfc4122(),
                ],
            );
        });
    }

    public function runs(
        Uuid $requesterId,
        int $limit = 50,
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException(
                'Organization run list limit must be between 1 and 100.',
            );
        }

        $rows = $this->connection->fetchAllAssociative(
            $this->runSelect().'
WHERE r.requester_id = :requester
ORDER BY r.updated_at DESC, r.id DESC
LIMIT :limit',
            [
                'requester' => $requesterId->toRfc4122(),
                'limit' => $limit,
            ],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(
            fn (array $row): OrganizationRunResult => $this->mapRun($row),
            $rows,
        );
    }

    public function run(
        Uuid $requesterId,
        Uuid $runId,
    ): OrganizationRunResult {
        $row = $this->connection->fetchAssociative(
            $this->runSelect().'
WHERE r.id = :run
  AND r.requester_id = :requester',
            [
                'run' => $runId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );

        if ($row === false) {
            throw new OrganizationRunUnavailableException(
                'Organization run is unavailable.',
            );
        }

        return $this->mapRun($row);
    }

    public function proposal(
        Uuid $requesterId,
        Uuid $proposalId,
    ): OrganizationProposalResult {
        $row = $this->connection->fetchAssociative(
            $this->proposalSelect().'
JOIN organization_runs r ON r.id = p.run_id
WHERE p.id = :proposal
  AND r.requester_id = :requester',
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

        return $this->mapProposal($row);
    }

    public function proposals(
        Uuid $requesterId,
        Uuid $runId,
    ): array {
        // Fail closed before reading any proposal details.
        $this->run($requesterId, $runId);

        $rows = $this->connection->fetchAllAssociative(
            $this->proposalSelect().'
WHERE p.run_id = :run
ORDER BY p.created_at ASC, p.id ASC',
            ['run' => $runId->toRfc4122()],
        );

        return array_map(
            fn (array $row): OrganizationProposalResult => $this->mapProposal($row),
            $rows,
        );
    }

    public function updatePayload(
        Uuid $requesterId,
        Uuid $proposalId,
        OrganizationProposalPayload $payload,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $proposalId,
            $payload,
        ): void {
            $row = $connection->fetchAssociative(
                <<<'SQL'
SELECT p.proposal_type, p.status
FROM organization_proposals p
JOIN organization_runs r ON r.id = p.run_id
WHERE p.id = :proposal
  AND r.requester_id = :requester
  AND r.status = 'ready_for_review'
FOR UPDATE OF p
SQL,
                [
                    'proposal' => $proposalId->toRfc4122(),
                    'requester' => $requesterId->toRfc4122(),
                ],
            );

            if ($row === false) {
                throw new OrganizationProposalUnavailableException(
                    'Organization proposal is unavailable for editing.',
                );
            }

            if (
                (string) $row['status']
                !== OrganizationProposalStatus::PendingReview->value
            ) {
                throw new \DomainException(
                    'Only a pending organization proposal can be edited.',
                );
            }

            if ((string) $row['proposal_type'] !== $payload->type->value) {
                throw new \InvalidArgumentException(
                    'Organization proposal type cannot be changed during review.',
                );
            }

            $mediaRows = $connection->fetchFirstColumn(
                'SELECT media_id
                 FROM organization_proposal_media
                 WHERE proposal_id = :proposal
                 ORDER BY position ASC',
                ['proposal' => $proposalId->toRfc4122()],
            );
            $mediaIds = array_map(
                static fn (mixed $id): Uuid => Uuid::fromString((string) $id),
                $mediaRows,
            );
            $this->assertPayloadMediaConsistency(
                $payload,
                $mediaIds,
            );

            $connection->executeStatement(
                'UPDATE organization_proposals
                 SET payload = CAST(:payload AS jsonb),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :proposal',
                [
                    'payload' => $payload->toJson(),
                    'proposal' => $proposalId->toRfc4122(),
                ],
            );
        });
    }

    public function reject(
        Uuid $requesterId,
        Uuid $proposalId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $proposalId,
        ): void {
            $row = $connection->fetchAssociative(
                <<<'SQL'
SELECT p.status
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

            if ($row === false) {
                throw new OrganizationProposalUnavailableException(
                    'Organization proposal is unavailable.',
                );
            }

            $status = OrganizationProposalStatus::from((string) $row['status']);
            if ($status === OrganizationProposalStatus::Rejected) {
                return;
            }

            if ($status !== OrganizationProposalStatus::PendingReview) {
                throw new \DomainException(
                    'Only a pending organization proposal can be rejected.',
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
        });
    }

    /**
     * @param list<Uuid> $mediaIds
     */
    private function assertVisibleMedia(
        Connection $connection,
        Uuid $requesterId,
        array $mediaIds,
    ): void {
        $rows = $connection->fetchFirstColumn(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT m.id
FROM media_assets m
WHERE m.id IN (:media_ids)
  AND '.AuthenticatedMediaAccessSql::predicate('m'),
            [
                'user' => $requesterId->toRfc4122(),
                'media_ids' => array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $mediaIds,
                ),
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        if (count($rows) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Organization run scope contains unavailable media.',
            );
        }
    }

    /**
     * @param list<Uuid> $mediaIds
     */
    private function assertRunMedia(
        Connection $connection,
        Uuid $runId,
        array $mediaIds,
    ): void {
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM organization_run_media
             WHERE run_id = :run
               AND media_id IN (:media_ids)',
            [
                'run' => $runId->toRfc4122(),
                'media_ids' => array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $mediaIds,
                ),
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        if ($count !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Organization proposal references media outside its analysis scope.',
            );
        }
    }

    private function lockDraftRun(
        Connection $connection,
        Uuid $requesterId,
        Uuid $runId,
    ): void {
        $status = $connection->fetchOne(
            <<<'SQL'
SELECT status
FROM organization_runs
WHERE id = :run
  AND requester_id = :requester
FOR UPDATE
SQL,
            [
                'run' => $runId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );

        if ($status === false) {
            throw new OrganizationRunUnavailableException(
                'Organization run is unavailable.',
            );
        }

        if ((string) $status !== OrganizationRunStatus::Draft->value) {
            throw new \DomainException(
                'Organization run no longer accepts proposals.',
            );
        }
    }

    /**
     * @param list<Uuid> $mediaIds
     */
    private function insertRunMedia(
        Connection $connection,
        Uuid $runId,
        array $mediaIds,
    ): void {
        foreach (array_chunk($mediaIds, 500) as $chunkIndex => $chunk) {
            $values = [];
            $parameters = ['run' => $runId->toRfc4122()];
            $offset = $chunkIndex * 500;

            foreach ($chunk as $index => $mediaId) {
                $name = 'media_'.$index;
                $values[] = sprintf(
                    '(:%s, %d)',
                    $name,
                    $offset + $index,
                );
                $parameters[$name] = $mediaId->toRfc4122();
            }

            $connection->executeStatement(
                'INSERT INTO organization_run_media (run_id, media_id, position)
                 SELECT :run, input.media_id::uuid, input.position
                 FROM (VALUES '.implode(', ', $values).') AS input(media_id, position)',
                $parameters,
            );
        }
    }

    /**
     * @param list<Uuid> $mediaIds
     */
    private function insertProposalMedia(
        Connection $connection,
        Uuid $proposalId,
        Uuid $runId,
        array $mediaIds,
    ): void {
        foreach (array_chunk($mediaIds, 500) as $chunkIndex => $chunk) {
            $values = [];
            $parameters = [
                'proposal' => $proposalId->toRfc4122(),
                'run' => $runId->toRfc4122(),
            ];
            $offset = $chunkIndex * 500;

            foreach ($chunk as $index => $mediaId) {
                $name = 'media_'.$index;
                $values[] = sprintf(
                    '(:%s, %d)',
                    $name,
                    $offset + $index,
                );
                $parameters[$name] = $mediaId->toRfc4122();
            }

            $connection->executeStatement(
                'INSERT INTO organization_proposal_media (
                    proposal_id,
                    run_id,
                    media_id,
                    position
                 )
                 SELECT
                    :proposal,
                    :run,
                    input.media_id::uuid,
                    input.position
                 FROM (VALUES '.implode(', ', $values).') AS input(media_id, position)',
                $parameters,
            );
        }
    }

    /**
     * @param list<OrganizationEvidence> $evidence
     */
    private function insertEvidence(
        Connection $connection,
        Uuid $proposalId,
        array $evidence,
    ): void {
        foreach ($evidence as $position => $item) {
            $connection->insert('organization_proposal_evidence', [
                'proposal_id' => $proposalId->toRfc4122(),
                'source_kind' => $item->source->value,
                'summary' => $item->summary,
                'position' => $position,
            ]);
        }
    }

    private function runSelect(): string
    {
        return <<<'SQL'
SELECT
    r.id,
    r.requester_id,
    r.producer_kind,
    r.provider_name,
    r.model_name,
    r.model_version,
    r.status,
    r.created_at,
    r.updated_at,
    (
        SELECT COUNT(*)
        FROM organization_run_media rm
        WHERE rm.run_id = r.id
    ) AS media_count
FROM organization_runs r
SQL;
    }

    private function proposalSelect(): string
    {
        return <<<'SQL'
SELECT
    p.id,
    p.run_id,
    p.proposal_type,
    p.status,
    p.payload,
    p.rationale,
    p.applied_resource_type,
    p.applied_resource_id,
    p.reviewed_at,
    p.created_at,
    p.updated_at
FROM organization_proposals p
SQL;
    }

    /** @param array<string,mixed> $row */
    private function mapRun(array $row): OrganizationRunResult
    {
        return new OrganizationRunResult(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['requester_id']),
            OrganizationProducer::fromPersisted(
                OrganizationProducerKind::from((string) $row['producer_kind']),
                $row['provider_name'] !== null ? (string) $row['provider_name'] : null,
                $row['model_name'] !== null ? (string) $row['model_name'] : null,
                $row['model_version'] !== null ? (string) $row['model_version'] : null,
            ),
            OrganizationRunStatus::from((string) $row['status']),
            (int) $row['media_count'],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /** @param array<string,mixed> $row */
    private function mapProposal(array $row): OrganizationProposalResult
    {
        $rawPayload = $row['payload'];
        if (is_string($rawPayload)) {
            $rawPayload = json_decode(
                $rawPayload,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        if (!is_array($rawPayload)) {
            throw new \RuntimeException(
                'Persisted organization proposal payload is invalid.',
            );
        }

        $proposalId = Uuid::fromString((string) $row['id']);

        $mediaRows = $this->connection->fetchFirstColumn(
            'SELECT media_id
             FROM organization_proposal_media
             WHERE proposal_id = :proposal
             ORDER BY position ASC, media_id ASC',
            ['proposal' => $proposalId->toRfc4122()],
        );
        $affectedMediaIds = array_map(
            static fn (mixed $id): Uuid => Uuid::fromString((string) $id),
            $mediaRows,
        );

        $evidenceRows = $this->connection->fetchAllAssociative(
            'SELECT source_kind, summary
             FROM organization_proposal_evidence
             WHERE proposal_id = :proposal
             ORDER BY position ASC, id ASC',
            ['proposal' => $proposalId->toRfc4122()],
        );
        $evidence = array_map(
            static fn (array $evidenceRow): OrganizationEvidence => new OrganizationEvidence(
                OrganizationEvidenceSource::from((string) $evidenceRow['source_kind']),
                (string) $evidenceRow['summary'],
            ),
            $evidenceRows,
        );

        return new OrganizationProposalResult(
            $proposalId,
            Uuid::fromString((string) $row['run_id']),
            OrganizationProposalStatus::from((string) $row['status']),
            OrganizationProposalPayload::fromArray(
                OrganizationProposalType::from((string) $row['proposal_type']),
                $rawPayload,
            ),
            (string) $row['rationale'],
            $affectedMediaIds,
            $evidence,
            $row['applied_resource_type'] !== null
                ? (string) $row['applied_resource_type']
                : null,
            $row['applied_resource_id'] !== null
                ? Uuid::fromString((string) $row['applied_resource_id'])
                : null,
            $row['reviewed_at'] !== null
                ? new DateTimeImmutable((string) $row['reviewed_at'])
                : null,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @param list<mixed> $mediaIds
     * @return list<Uuid>
     */
    private function normalizeMediaIds(
        array $mediaIds,
        int $maximum,
        string $label,
    ): array {
        if ($mediaIds === [] || count($mediaIds) > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                '%s must contain 1-%d MediaAssets.',
                ucfirst($label),
                $maximum,
            ));
        }

        $unique = [];
        foreach ($mediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    ucfirst($label).' must contain UUID objects only.',
                );
            }

            $unique[$mediaId->toRfc4122()] = $mediaId;
        }

        if (count($unique) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                ucfirst($label).' must not contain duplicate MediaAssets.',
            );
        }

        return array_values($unique);
    }

    /**
     * @param list<mixed> $evidence
     * @return list<OrganizationEvidence>
     */
    private function evidence(array $evidence): array
    {
        if (
            $evidence === []
            || count($evidence) > self::MAX_EVIDENCE_PER_PROPOSAL
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Organization proposal must contain 1-%d evidence items.',
                self::MAX_EVIDENCE_PER_PROPOSAL,
            ));
        }

        foreach ($evidence as $item) {
            if (!$item instanceof OrganizationEvidence) {
                throw new \InvalidArgumentException(
                    'Organization proposal evidence has an invalid type.',
                );
            }
        }

        return array_values($evidence);
    }

    private function rationale(string $rationale): string
    {
        $rationale = trim($rationale);
        $length = iconv_strlen($rationale, 'UTF-8');

        if ($rationale === '' || $length === false || $length > 5000) {
            throw new \InvalidArgumentException(
                'Organization proposal rationale must contain 1-5000 characters.',
            );
        }

        return $rationale;
    }

    /**
     * @param list<Uuid> $affectedMediaIds
     */
    private function assertPayloadMediaConsistency(
        OrganizationProposalPayload $payload,
        array $affectedMediaIds,
    ): void {
        $ids = array_fill_keys(
            array_map(
                static fn (Uuid $id): string => $id->toRfc4122(),
                $affectedMediaIds,
            ),
            true,
        );
        $data = $payload->payload();

        if ($payload->type === OrganizationProposalType::Cover) {
            $mediaId = (string) $data['media_id'];
            if (!isset($ids[$mediaId])) {
                throw new \InvalidArgumentException(
                    'Cover proposal media must be part of its affected media set.',
                );
            }
        }

        if (
            $payload->type === OrganizationProposalType::TitleDescription
            && ($data['target_type'] ?? null) === 'media'
            && !isset($ids[(string) $data['target_id']])
        ) {
            throw new \InvalidArgumentException(
                'Media title/description target must be part of its affected media set.',
            );
        }
    }
}
