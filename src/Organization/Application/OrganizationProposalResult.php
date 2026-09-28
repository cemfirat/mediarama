<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalResult
{
    /**
     * @param list<Uuid> $affectedMediaIds
     * @param list<OrganizationEvidence> $evidence
     */
    public function __construct(
        public Uuid $id,
        public Uuid $runId,
        public OrganizationProposalStatus $status,
        public OrganizationProposalPayload $payload,
        public string $rationale,
        public array $affectedMediaIds,
        public array $evidence,
        public ?DateTimeImmutable $reviewedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
