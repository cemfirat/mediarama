<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Mediarama\Organization\Application\OrganizationProposalMediaPreviewResult;
use Mediarama\Organization\Application\OrganizationProposalReviewItemResult;
use Mediarama\Organization\Application\OrganizationProposalReviewQuery;
use Mediarama\Organization\Application\OrganizationRunUnavailableException;
use Mediarama\Organization\Domain\OrganizationAppliedEntityType;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationProposalReviewQuery implements OrganizationProposalReviewQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function proposals(
        Uuid $requesterId,
        Uuid $runId,
        int $previewLimit = 12,
    ): array {
        if ($previewLimit < 1 || $previewLimit > 50) {
            throw new \InvalidArgumentException(
                'Organization proposal preview limit must be between 1 and 50.',
            );
        }

        $exists = (bool) $this->connection->fetchOne(
            'SELECT EXISTS (
                SELECT 1
                FROM organization_runs
                WHERE id = :run
                  AND requester_id = :requester
            )',
            [
                'run' => $runId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );
        if (!$exists) {
            throw new OrganizationRunUnavailableException(
                'Organization run is unavailable.',
            );
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    p.id,
    p.run_id,
    p.proposal_type,
    p.status,
    p.payload,
    p.rationale,
    p.reviewed_at,
    p.applied_entity_type,
    p.applied_entity_id,
    p.created_at,
    p.updated_at,
    (
        SELECT COUNT(*)
        FROM organization_proposal_media pm
        WHERE pm.proposal_id = p.id
    ) AS affected_media_count
FROM organization_proposals p
WHERE p.run_id = :run
ORDER BY p.created_at ASC, p.id ASC
SQL,
            ['run' => $runId->toRfc4122()],
        );

        if ($rows === []) {
            return [];
        }

        $evidenceRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    e.proposal_id,
    e.source_kind,
    e.summary
FROM organization_proposal_evidence e
JOIN organization_proposals p ON p.id = e.proposal_id
WHERE p.run_id = :run
ORDER BY e.proposal_id ASC, e.position ASC, e.id ASC
SQL,
            ['run' => $runId->toRfc4122()],
        );

        /** @var array<string,list<OrganizationEvidence>> $evidence */
        $evidence = [];
        foreach ($evidenceRows as $row) {
            $proposalId = (string) $row['proposal_id'];
            $evidence[$proposalId][] = new OrganizationEvidence(
                OrganizationEvidenceSource::from((string) $row['source_kind']),
                (string) $row['summary'],
            );
        }

        $previewSql = CollectionAccessSql::authenticatedVisibleCollectionsCte()
            ."\n, ranked_preview AS (\n"
            .'    SELECT '
            .'pm.proposal_id, m.id, m.title, m.media_type, '
            .'ROW_NUMBER() OVER (PARTITION BY pm.proposal_id ORDER BY pm.position ASC, m.id ASC) AS preview_position '
            .'FROM organization_proposal_media pm '
            .'JOIN organization_proposals p ON p.id = pm.proposal_id '
            .'JOIN organization_runs r ON r.id = p.run_id '
            .'JOIN media_assets m ON m.id = pm.media_id '
            .'WHERE p.run_id = :run '
            .'AND r.requester_id = :requester '
            .'AND '.AuthenticatedMediaAccessSql::predicate('m')
            ."\n)\n"
            .'SELECT proposal_id, id, title, media_type '
            .'FROM ranked_preview '
            .'WHERE preview_position <= :preview_limit '
            .'ORDER BY proposal_id ASC, preview_position ASC';

        $previewRows = $this->connection->fetchAllAssociative(
            $previewSql,
            [
                'run' => $runId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
                'user' => $requesterId->toRfc4122(),
                'preview_limit' => $previewLimit,
            ],
            ['preview_limit' => ParameterType::INTEGER],
        );

        /** @var array<string,list<OrganizationProposalMediaPreviewResult>> $previews */
        $previews = [];
        foreach ($previewRows as $row) {
            $proposalId = (string) $row['proposal_id'];
            $previews[$proposalId][] = new OrganizationProposalMediaPreviewResult(
                Uuid::fromString((string) $row['id']),
                $row['title'] !== null ? (string) $row['title'] : null,
                (string) $row['media_type'],
            );
        }

        return array_map(
            function (array $row) use ($evidence, $previews): OrganizationProposalReviewItemResult {
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

                $proposalId = (string) $row['id'];

                return new OrganizationProposalReviewItemResult(
                    Uuid::fromString($proposalId),
                    Uuid::fromString((string) $row['run_id']),
                    OrganizationProposalStatus::from((string) $row['status']),
                    OrganizationProposalPayload::fromArray(
                        OrganizationProposalType::from(
                            (string) $row['proposal_type'],
                        ),
                        $rawPayload,
                    ),
                    (string) $row['rationale'],
                    (int) $row['affected_media_count'],
                    $evidence[$proposalId] ?? [],
                    $previews[$proposalId] ?? [],
                    $row['reviewed_at'] !== null
                        ? new DateTimeImmutable((string) $row['reviewed_at'])
                        : null,
                    $row['applied_entity_type'] !== null
                        ? OrganizationAppliedEntityType::from(
                            (string) $row['applied_entity_type'],
                        )
                        : null,
                    $row['applied_entity_id'] !== null
                        ? Uuid::fromString(
                            (string) $row['applied_entity_id'],
                        )
                        : null,
                    new DateTimeImmutable((string) $row['created_at']),
                    new DateTimeImmutable((string) $row['updated_at']),
                );
            },
            $rows,
        );
    }
}
